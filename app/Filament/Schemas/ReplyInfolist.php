<?php

namespace App\Filament\Schemas;

use Filament\Infolists\Components\TextEntry;

final class ReplyInfolist
{
    /**
     * @param  array<string, mixed>  $record
     * @return array<int, TextEntry>
     */
    public static function forRecord(array $record): array
    {
        return [
            self::entry('client', 'Client', $record),
            self::entry('contact_email', 'Contact email', $record),
            self::entry('receipt_source', 'Receipt source', $record),
            self::entry('inbound', 'Inbound', $record),
            self::entry('clock_start', 'Clock start', $record),
            self::entry('reply_due', 'Reply due', $record),
            self::entry('id', 'Note', $record),
            self::entry('summary', 'Summary', $record),
            self::entry('body', 'Note body', $record)->columnSpanFull(),
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private static function entry(string $name, string $label, array $record): TextEntry
    {
        return TextEntry::make($name)
            ->label($label)
            ->state((string) ($record[$name] ?? ''));
    }
}
