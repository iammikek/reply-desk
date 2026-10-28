<?php

namespace App\Filament\Tables;

use App\Desk\Queue\ClosedThread;
use App\Desk\Queue\ListClosedThreads;
use App\Filament\Schemas\ClosedThreadInfolist;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;

final class ClosedThreadsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->records(fn (?string $search, ?array $filters, ?string $sortColumn, ?string $sortDirection, int|string|null $page = 1, int|string|null $recordsPerPage = null): LengthAwarePaginator => self::paginate($search, $sortColumn, $sortDirection, $page, $recordsPerPage))
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->columns([
                InboundTable::client(),
                InboundTable::summary(),
                InboundTable::inbound(),
                TextColumn::make('sent')
                    ->label('Sent')
                    ->sortable(),
                TextColumn::make('subject')
                    ->label('Subject')
                    ->searchable()
                    ->wrap(),
            ])
            ->recordUrl(InboundTable::viewUrl())
            ->modifyUngroupedRecordActionsUsing(fn (Action $action): Action => $action->button())
            ->recordActions([
                Action::make('view')
                    ->label('Thread')
                    ->slideOver()
                    ->modalHeading('Closed thread')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->schema(fn (array $record): array => ClosedThreadInfolist::forRecord($record)),
            ])
            ->defaultSort('sent', 'desc')
            ->emptyStateHeading('No closed replies')
            ->emptyStateDescription('A thread appears here after the reply is marked sent.')
            ->modelLabel('thread')
            ->pluralModelLabel('threads');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function records(?string $search, ?string $sortColumn, ?string $sortDirection): array
    {
        $records = array_map(
            self::record(...),
            app(ListClosedThreads::class)(),
        );

        return InboundTable::arrange(
            $records,
            $search,
            $sortColumn,
            $sortDirection,
            fn (array $record): string => $record['client'].' '.$record['summary'].' '.$record['subject'].' '.self::attachmentNames($record),
        );
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private static function attachmentNames(array $record): string
    {
        $names = [(string) ($record['attachment'] ?? '')];

        foreach ($record['replies'] ?? [] as $reply) {
            if (is_array($reply)) {
                $names[] = (string) ($reply['attachment'] ?? '');
            }
        }

        return implode(' ', $names);
    }

    private static function paginate(?string $search, ?string $sortColumn, ?string $sortDirection, int|string|null $page, int|string|null $recordsPerPage): LengthAwarePaginator
    {
        $records = self::records($search, $sortColumn, $sortDirection);
        $total = count($records);
        $perPage = self::perPage($recordsPerPage, $total);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) $page), $lastPage);

        return new LengthAwarePaginator(
            array_slice($records, ($page - 1) * $perPage, $perPage),
            $total,
            $perPage,
            $page,
        )->onEachSide(0);
    }

    private static function perPage(int|string|null $recordsPerPage, int $total): int
    {
        if ($recordsPerPage === 'all') {
            return max($total, 1);
        }

        $perPage = (int) $recordsPerPage;

        return $perPage > 0 ? $perPage : 10;
    }

    /**
     * @return array<string, mixed>
     */
    private static function record(ClosedThread $thread): array
    {
        return InboundTable::withKey($thread->toArray(), $thread->id);
    }
}
