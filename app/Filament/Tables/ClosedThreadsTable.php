<?php

namespace App\Filament\Tables;

use App\Desk\ClosedThread;
use App\Desk\ListClosedThreads;
use App\Filament\Schemas\ClosedThreadInfolist;
use Filament\Actions\Action;
use Filament\Support\ArrayRecord;
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
                TextColumn::make('sent')
                    ->label('Sent')
                    ->sortable(),
                TextColumn::make('subject')
                    ->label('Subject')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('id')
                    ->label('Note')
                    ->toggleable(),
            ])
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
            fn (ClosedThread $thread): array => self::record($thread),
            app(ListClosedThreads::class)(),
        );

        if (filled($search)) {
            $needle = mb_strtolower($search);
            $records = array_values(array_filter(
                $records,
                fn (array $record): bool => str_contains(mb_strtolower($record['client'].' '.$record['summary'].' '.$record['subject']), $needle),
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

    private static function paginate(?string $search, ?string $sortColumn, ?string $sortDirection, int|string|null $page, int|string|null $recordsPerPage): LengthAwarePaginator
    {
        $records = self::records($search, $sortColumn, $sortDirection);
        $total = count($records);
        $perPage = self::perPage($recordsPerPage, $total);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) $page), $lastPage);

        return (new LengthAwarePaginator(
            array_slice($records, ($page - 1) * $perPage, $perPage),
            $total,
            $perPage,
            $page,
        ))->onEachSide(0);
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
        $row = $thread->toArray();
        $row[ArrayRecord::getKeyName()] = $thread->id;

        return $row;
    }
}
