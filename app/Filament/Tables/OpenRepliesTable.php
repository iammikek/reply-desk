<?php

namespace App\Filament\Tables;

use App\Clock\ReceiptSource;
use App\Clock\ReplyClock;
use App\Desk\DeskException;
use App\Desk\DiscardDraft;
use App\Desk\ListOpenReplies;
use App\Desk\LondonTime;
use App\Desk\MarkReplySent;
use App\Desk\OpenReply;
use App\Desk\SaveDraft;
use App\Filament\Pages\OpenQueue;
use App\Filament\Schemas\DraftForm;
use App\Filament\Schemas\MarkSentForm;
use App\Filament\Schemas\ReplyInfolist;
use DateTimeImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\ArrayRecord;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

final class OpenRepliesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->records(fn (?string $search, ?array $filters, ?string $sortColumn, ?string $sortDirection): array => self::records($search, $filters, $sortColumn, $sortDirection))
            ->columns([
                TextColumn::make('client')
                    ->label('Client')
                    ->searchable()
                    ->sortable()
                    ->grow(false),
                TextColumn::make('summary')
                    ->label('Summary')
                    ->searchable()
                    ->wrap()
                    ->grow(),
                TextColumn::make('inbound')
                    ->label('Inbound')
                    ->sortable(),
                TextColumn::make('clock_start')
                    ->label('Clock start')
                    ->sortable(),
                TextColumn::make('reply_due')
                    ->label('Reply due')
                    ->sortable()
                    ->badge()
                    ->color(fn (array $record): string => $record['overdue'] ? 'danger' : 'gray'),
                TextColumn::make('id')
                    ->label('Note')
                    ->toggleable(),
                TextColumn::make('has_draft')
                    ->label('Draft')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Draft' : '')
                    ->color('info'),
            ])
            ->filters([
                TernaryFilter::make('overdue')
                    ->label('Overdue')
                    ->trueLabel('Overdue')
                    ->falseLabel('Not overdue'),
            ])
            ->modifyUngroupedRecordActionsUsing(fn (Action $action): Action => $action->button())
            ->recordActions([
                Action::make('view')
                    ->label('Note')
                    ->slideOver()
                    ->modalHeading('Clock note')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->schema(fn (array $record): array => ReplyInfolist::forRecord($record)),
                Action::make('saveDraft')
                    ->label('Draft')
                    ->fillForm(fn (array $record): array => [
                        'subject' => $record['draft_subject'],
                        'body' => $record['draft_body'],
                    ])
                    ->schema(fn (array $record): array => [
                        ...ReplyInfolist::earlier($record),
                        ...DraftForm::components(),
                    ])
                    ->action(function (array $record, array $data, OpenQueue $livewire): void {
                        try {
                            app(SaveDraft::class)(
                                (string) $record['id'],
                                (string) $data['subject'],
                                (string) $data['body'],
                            );
                        } catch (DeskException $exception) {
                            Notification::make()->title($exception->getMessage())->danger()->send();

                            throw new Halt;
                        }

                        $livewire->flushCachedTableRecords();
                        Notification::make()->title('Draft saved. The reply is still due.')->success()->send();
                    }),
                Action::make('discardDraft')
                    ->label('Discard draft')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (array $record): bool => $record['has_draft'] === true)
                    ->action(function (array $record, OpenQueue $livewire): void {
                        app(DiscardDraft::class)((string) $record['id']);
                        $livewire->flushCachedTableRecords();
                        Notification::make()->title('Draft discarded')->success()->send();
                    }),
                Action::make('markSent')
                    ->label('Mark sent')
                    ->schema(MarkSentForm::components())
                    ->action(function (array $record, array $data, OpenQueue $livewire): void {
                        try {
                            app(MarkReplySent::class)(
                                (string) $record['id'],
                                LondonTime::parse($data['sent']),
                                (string) $data['subject'],
                                (string) $data['reply'],
                            );
                        } catch (DeskException $exception) {
                            Notification::make()->title($exception->getMessage())->danger()->send();

                            throw new Halt;
                        }

                        $livewire->flushCachedTableRecords();
                        Notification::make()->title('Reply marked sent')->success()->send();
                    }),
            ])
            ->defaultSort('reply_due')
            ->emptyStateHeading('No open replies')
            ->emptyStateDescription('Log an inbound when a client email arrives.')
            ->modelLabel('reply')
            ->pluralModelLabel('replies');
    }

    /**
     * @param  array<string, mixed>|null  $filters
     * @return list<array<string, mixed>>
     */
    private static function records(?string $search, ?array $filters, ?string $sortColumn, ?string $sortDirection): array
    {
        $now = DateTimeImmutable::createFromInterface(now(ReplyClock::ZONE));
        $records = array_map(
            fn (OpenReply $reply): array => self::record($reply, $now),
            app(ListOpenReplies::class)($now),
        );

        $overdue = $filters['overdue']['value'] ?? null;

        if ($overdue === true || $overdue === 1 || $overdue === '1') {
            $records = array_values(array_filter($records, fn (array $record): bool => $record['overdue'] === true));
        } elseif ($overdue === false || $overdue === 0 || $overdue === '0') {
            $records = array_values(array_filter($records, fn (array $record): bool => $record['overdue'] === false));
        }

        if (filled($search)) {
            $needle = mb_strtolower($search);
            $records = array_values(array_filter(
                $records,
                fn (array $record): bool => str_contains(mb_strtolower($record['client'].' '.$record['summary']), $needle),
            ));
        }

        if (filled($sortColumn)) {
            usort($records, function (array $left, array $right) use ($sortColumn, $sortDirection): int {
                $compared = ($left[$sortColumn] ?? '') <=> ($right[$sortColumn] ?? '');

                return $sortDirection === 'desc' ? -$compared : $compared;
            });
        }

        return $records;
    }

    /**
     * @return array<string, mixed>
     */
    private static function record(OpenReply $reply, DateTimeImmutable $now): array
    {
        $row = $reply->toArray($now);
        $row['receipt_source'] = $reply->receiptSource === ReceiptSource::MailClient ? 'Mail client' : 'Logged';
        $row[ArrayRecord::getKeyName()] = $reply->id;

        return $row;
    }
}
