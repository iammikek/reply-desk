<?php

namespace App\Filament\Tables;

use App\Filament\Pages\Client;
use App\Filament\Resources\Inbounds\InboundResource;
use Closure;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;

final class InboundTable
{
    public static function client(): TextColumn
    {
        return TextColumn::make('client')
            ->label('Client')
            ->searchable()
            ->sortable()
            ->grow(false)
            ->url(fn (array $record): string => Client::urlFor((string) $record['company_id'], (string) $record['contact_id']));
    }

    public static function summary(): TextColumn
    {
        return TextColumn::make('summary')
            ->label('Summary')
            ->searchable()
            ->wrap()
            ->grow();
    }

    public static function inbound(): TextColumn
    {
        return TextColumn::make('inbound')
            ->label('Inbound')
            ->sortable();
    }

    public static function viewUrl(): Closure
    {
        return fn (array $record): string => InboundResource::getUrl('view', ['record' => $record['id']]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function withKey(array $row, string $id): array
    {
        $row[ArrayRecord::getKeyName()] = $id;

        return $row;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @param  callable(array<string, mixed>): string  $haystack
     * @return list<array<string, mixed>>
     */
    public static function arrange(array $records, ?string $search, ?string $sortColumn, ?string $sortDirection, callable $haystack): array
    {
        if (filled($search)) {
            $needle = mb_strtolower($search);
            $records = array_values(array_filter(
                $records,
                fn (array $record): bool => str_contains(mb_strtolower($haystack($record)), $needle),
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
}
