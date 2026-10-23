<?php

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
     * @return list<array{at: string, kind: string, title: string, body: string}>
     */
    private static function events(array $record): array
    {
        $events = [[
            'at' => (string) ($record['inbound'] ?? ''),
            'kind' => 'Client email',
            'title' => (string) ($record['summary'] ?? ''),
            'body' => trim((string) ($record['client_email'] ?? '')),
        ]];

        foreach ($record['replies'] ?? [] as $reply) {
            if (! is_array($reply)) {
                continue;
            }

            $events[] = [
                'at' => (string) ($reply['sent'] ?? ''),
                'kind' => 'Reply',
                'title' => (string) ($reply['subject'] ?? ''),
                'body' => trim((string) ($reply['body'] ?? '')),
            ];
        }

        return $events;
    }
}
