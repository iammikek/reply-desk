<?php

namespace App\Filament\Schemas;

use App\Clock\ClockNote;
use Filament\Infolists\Components\TextEntry;

final class ReplyInfolist
{
    /**
     * @param  array<string, mixed>  $record
     * @return array<int, TextEntry>
     */
    public static function forRecord(array $record): array
    {
        $entries = [
            self::entry('client', 'Client', $record),
            self::entry('contact_email', 'Contact email', $record),
            self::entry('receipt_source', 'Receipt source', $record),
            self::entry('inbound', 'Inbound', $record),
            self::entry('clock_start', 'Clock start', $record),
            self::entry('reply_due', 'Reply due', $record),
            self::entry('id', 'Note', $record),
            self::entry('summary', preg_match('/^Subject: /m', (string) ($record['body'] ?? '')) === 1 ? 'Subject' : 'Summary', $record),
        ];

        $mail = ClockNote::correspondence((string) ($record['body'] ?? ''));

        if ($mail['client'] !== '') {
            $entries[] = self::mail('client_email_text', 'Client email', $mail['client']);
        }

        if ($mail['reply'] !== '') {
            $entries[] = self::mail('reply_text', 'Reply', $mail['reply']);
        }

        $earlier = self::earlierText($record);

        if ($earlier !== '') {
            $entries[] = self::mail('earlier', 'Earlier on this client', $earlier);
        }

        $entries[] = self::entry('body', 'Note body', $record)
            ->extraAttributes(['style' => 'white-space: pre-wrap'])
            ->columnSpanFull();

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<int, TextEntry>
     */
    public static function earlier(array $record): array
    {
        $earlier = self::earlierText($record);

        if ($earlier === '') {
            return [];
        }

        return [self::mail('earlier', 'Earlier on this client', $earlier)];
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private static function earlierText(array $record): string
    {
        $threads = $record['earlier'] ?? [];

        if (! is_array($threads) || $threads === []) {
            return '';
        }

        $blocks = [];

        foreach ($threads as $thread) {
            if (! is_array($thread)) {
                continue;
            }

            $lines = [
                (string) ($thread['receipt'] ?? ''),
                (string) ($thread['summary'] ?? ''),
            ];
            $client = (string) ($thread['client'] ?? '');

            if (trim($client) !== '') {
                $lines[] = '';
                $lines[] = 'Client email';
                $lines[] = $client;
            }

            foreach ($thread['replies'] ?? [] as $reply) {
                if (! is_array($reply)) {
                    continue;
                }

                $lines[] = '';
                $lines[] = 'Reply '.(string) ($reply['sent'] ?? '');
                $lines[] = 'Subject: '.(string) ($reply['subject'] ?? '');
                $body = (string) ($reply['body'] ?? '');

                if (trim($body) !== '') {
                    $lines[] = '';
                    $lines[] = $body;
                }
            }

            $blocks[] = implode("\n", $lines);
        }

        return implode("\n\n", $blocks);
    }

    private static function mail(string $name, string $label, string $text): TextEntry
    {
        return TextEntry::make($name)
            ->label($label)
            ->state($text)
            ->extraAttributes(['style' => 'white-space: pre-wrap'])
            ->columnSpanFull();
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
