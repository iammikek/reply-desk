<?php

namespace App\Filament\Tables;

use App\Clock\ReplyClock;
use App\Desk\Actions\DiscardDraft;
use App\Desk\Actions\MarkReplySent;
use App\Desk\Actions\SaveDraft;
use App\Desk\Actions\SendReply;
use App\Desk\DeskException;
use App\Desk\LondonTime;
use App\Desk\Notes\NoteFile;
use App\Desk\Queue\ListOpenReplies;
use App\Desk\Queue\OpenReply;
use App\Filament\DeskNotify;
use App\Filament\MountedReply;
use App\Filament\Resources\Inbounds\Pages\ListOpenQueue;
use App\Filament\Schemas\AttachmentField;
use App\Filament\Schemas\DraftForm;
use App\Filament\Schemas\MarkSentForm;
use App\Filament\Schemas\ReplyInfolist;
use DateTimeImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\Factory;

final class OpenRepliesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->records(fn (?string $search, ?array $filters, ?string $sortColumn, ?string $sortDirection): array => self::records($search, $filters, $sortColumn, $sortDirection))
            ->columns([
                InboundTable::client(),
                InboundTable::summary(),
                InboundTable::inbound(),
                TextColumn::make('clock_start')
                    ->label('Clock start')
                    ->sortable(),
                TextColumn::make('reply_due')
                    ->label('Reply due')
                    ->sortable()
                    ->badge()
                    ->color(fn (array $record): string => $record['overdue'] ? 'danger' : 'gray'),
                TextColumn::make('id')
                    ->label('Note id')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('has_draft')
                    ->label('Draft')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Draft' : '—')
                    ->color(fn (bool $state): string => $state ? 'info' : 'gray'),
            ])
            ->filters([
                TernaryFilter::make('overdue')
                    ->label('Overdue')
                    ->trueLabel('Overdue')
                    ->falseLabel('Not overdue'),
            ])
            ->recordUrl(InboundTable::viewUrl())
            ->modifyUngroupedRecordActionsUsing(fn (Action $action): Action => $action->button())
            ->recordActions([
                tap(
                    Action::make('reply')
                        ->label('Reply')
                        ->fillForm(fn (array $record): array => [
                            'subject' => $record['draft_subject'],
                            'body' => $record['draft_body'],
                        ])
                        ->extraModalWindowAttributes(['class' => 'reply-compose'])
                        ->modalContent(fn (array $record): Factory|\Illuminate\Contracts\View\View => view('filament.reply-thread', [
                            'earlier' => ReplyInfolist::earlierText($record),
                        ]))
                        ->schema([
                            ...DraftForm::components(),
                            AttachmentField::make(),
                        ])
                        ->modalSubmitActionLabel('Send')
                        ->modalCancelActionLabel('Cancel'),
                    function (Action $reply): void {
                        $reply->modalCancelAction(function (Action $action) use ($reply): Action {
                            if ($livewire = $reply->getLivewire()) {
                                $action->livewire($livewire);
                            }

                            return $action
                                ->close(false)
                                ->modalHeading(fn (): ?string => MountedReply::asksToSave($action) ? 'Save this as a draft?' : null)
                                ->modalDescription(fn (): ?string => MountedReply::asksToSave($action) ? 'The reply has not been sent.' : null)
                                ->modalSubmitActionLabel('Save draft')
                                ->modalCancelAction(fn (Action $dontSave): Action => $dontSave
                                    ->label("Don't save")
                                    ->cancelParentActions()
                                    ->cancelParentActionsOnClose())
                                ->cancelParentActions()
                                ->action(function () use ($action): void {
                                    $data = MountedReply::form($action);

                                    if (! MountedReply::asksToSave($action)) {
                                        return;
                                    }

                                    $subject = trim((string) ($data['subject'] ?? ''));
                                    $livewire = $action->getLivewire();

                                    try {
                                        app(SaveDraft::class)(
                                            MountedReply::recordKey($action),
                                            $subject !== '' ? $subject : 'Reply',
                                            (string) ($data['body'] ?? ''),
                                        );
                                    } catch (DeskException $exception) {
                                        DeskNotify::halt($exception);
                                    }

                                    if ($livewire instanceof ListOpenQueue) {
                                        $livewire->flushCachedTableRecords();
                                    }

                                    Notification::make()->title('Draft saved. The reply is still due.')->success()->send();
                                });
                        })
                            ->action(function (array $record, array $data, ListOpenQueue $livewire): void {
                                try {
                                    app(SendReply::class)(
                                        (string) $record['id'],
                                        (string) $data['subject'],
                                        (string) $data['body'],
                                        NoteFile::fromForm($data['attachment'] ?? null, $data['attachment_name'] ?? null),
                                    );
                                } catch (DeskException $exception) {
                                    DeskNotify::halt($exception);
                                }

                                $livewire->flushCachedTableRecords();
                                Notification::make()->title('Reply sent')->success()->send();
                            });
                    },
                ),
                Action::make('view')
                    ->label('Open')
                    ->slideOver()
                    ->modalHeading('Clock note')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->schema(fn (array $record): array => ReplyInfolist::forRecord($record)),
                ActionGroup::make([
                    Action::make('markSent')
                        ->label('Mark sent')
                        ->schema(MarkSentForm::components())
                        ->action(function (array $record, array $data, ListOpenQueue $livewire): void {
                            try {
                                app(MarkReplySent::class)(
                                    (string) $record['id'],
                                    LondonTime::parse($data['sent']),
                                    (string) $data['subject'],
                                    (string) $data['reply'],
                                    NoteFile::fromForm($data['attachment'] ?? null, $data['attachment_name'] ?? null),
                                );
                            } catch (DeskException $exception) {
                                DeskNotify::halt($exception);
                            }

                            $livewire->flushCachedTableRecords();
                            Notification::make()->title('Reply marked sent')->success()->send();
                        }),
                    Action::make('discardDraft')
                        ->label('Discard draft')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->visible(fn (array $record): bool => $record['has_draft'] === true)
                        ->action(function (array $record, ListOpenQueue $livewire): void {
                            app(DiscardDraft::class)((string) $record['id']);
                            $livewire->flushCachedTableRecords();
                            Notification::make()->title('Draft discarded')->success()->send();
                        }),
                ])
                    ->label('More')
                    ->icon(Heroicon::EllipsisVertical)
                    ->button(),
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
        $now = DateTimeImmutable::createFromInterface(now(ReplyClock::zoneName()));
        $overdue = $filters['overdue']['value'] ?? null;
        $onlyOverdue = match (true) {
            $overdue === true, $overdue === 1, $overdue === '1' => true,
            $overdue === false, $overdue === 0, $overdue === '0' => false,
            default => null,
        };
        $records = array_map(
            fn (OpenReply $reply): array => self::record($reply, $now),
            app(ListOpenReplies::class)($now, $onlyOverdue),
        );

        return InboundTable::arrange(
            $records,
            $search,
            $sortColumn,
            $sortDirection,
            fn (array $record): string => $record['client'].' '.$record['summary'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function record(OpenReply $reply, DateTimeImmutable $now): array
    {
        $row = $reply->toArray($now);
        $row['receipt_source'] = $reply->receiptSource->label();

        return InboundTable::withKey($row, $reply->id);
    }
}
