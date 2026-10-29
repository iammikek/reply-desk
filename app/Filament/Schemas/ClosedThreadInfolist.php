<?php

declare(strict_types=1);

namespace App\Filament\Schemas;

use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\View;

final class ClosedThreadInfolist
{
    /**
     * @param  array<string, mixed>  $record
     * @return array<int, Component>
     */
    public static function forRecord(array $record): array
    {
        return [
            View::make('filament.thread-timeline')
                ->viewData([
                    'client' => (string) ($record['client'] ?? ''),
                    'email' => (string) ($record['contact_email'] ?? ''),
                    'events' => self::events($record),
                ])
                ->columnSpanFull(),
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<array{at: string, kind: string, title: string, body: string, attachment: string, note_id: string}>
     */
    private static function events(array $record): array
    {
        $events = [[
            'at' => (string) ($record['inbound'] ?? ''),
            'kind' => 'Client email',
            'title' => (string) ($record['summary'] ?? ''),
            'body' => (string) ($record['client_email'] ?? ''),
            'attachment' => (string) ($record['attachment'] ?? ''),
            'note_id' => (string) ($record['id'] ?? ''),
        ]];

        foreach ($record['replies'] ?? [] as $reply) {
            if (! is_array($reply)) {
                continue;
            }

            $sent = (string) ($reply['sent'] ?? '');
            $events[] = [
                'at' => $sent,
                'kind' => 'Reply',
                'title' => (string) ($reply['subject'] ?? ''),
                'detail' => ReplyInfolist::repliedIn((string) ($record['inbound'] ?? ''), $sent),
                'body' => (string) ($reply['body'] ?? ''),
                'attachment' => (string) ($reply['attachment'] ?? ''),
                'note_id' => (string) ($reply['note_id'] ?? ''),
            ];
        }

        if (($record['closed_without_reply'] ?? false) === true) {
            $events[] = [
                'at' => (string) ($record['closed_at'] ?? $record['sent'] ?? ''),
                'kind' => 'Closed',
                'title' => 'No reply',
                'body' => '',
                'attachment' => '',
                'note_id' => '',
            ];
        }

        return $events;
    }
}
