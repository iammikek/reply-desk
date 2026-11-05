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
            View::make('filament.client-threads')
                ->viewData([
                    'client' => (string) ($record['client'] ?? ''),
                    'email' => (string) ($record['contact_email'] ?? ''),
                    'replies' => [self::thread($record)],
                    'outreach' => [],
                    'showClient' => true,
                ])
                ->columnSpanFull(),
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{id: string, started_at: string, subject: string, summary: string, status: string, events: list<array{at: string, side: string, kind: string, body: string, attachment: string, note_id: string}>}
     */
    private static function thread(array $record): array
    {
        $closedWithoutReply = ($record['closed_without_reply'] ?? false) === true;

        return [
            'id' => (string) ($record['id'] ?? ''),
            'started_at' => (string) ($record['inbound'] ?? ''),
            'subject' => self::subject($record),
            'summary' => (string) ($record['summary'] ?? ''),
            'status' => $closedWithoutReply ? 'closed' : 'replied',
            'events' => self::events($record, $closedWithoutReply),
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private static function subject(array $record): string
    {
        foreach ($record['replies'] ?? [] as $reply) {
            if (! is_array($reply)) {
                continue;
            }

            $subject = trim((string) ($reply['subject'] ?? ''));

            if ($subject !== '') {
                return $subject;
            }
        }

        return trim((string) ($record['summary'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<array{at: string, side: string, kind: string, body: string, attachment: string, note_id: string}>
     */
    private static function events(array $record, bool $closedWithoutReply): array
    {
        $events = [];
        $clientBody = (string) ($record['client_email'] ?? '');

        if ($clientBody !== '') {
            $events[] = [
                'at' => (string) ($record['inbound'] ?? ''),
                'side' => 'client',
                'kind' => 'Client email',
                'body' => $clientBody,
                'attachment' => (string) ($record['attachment'] ?? ''),
                'note_id' => (string) ($record['id'] ?? ''),
            ];
        }

        foreach ($record['replies'] ?? [] as $reply) {
            if (! is_array($reply)) {
                continue;
            }

            $sent = (string) ($reply['sent'] ?? '');
            $events[] = [
                'at' => $sent,
                'side' => 'owner',
                'kind' => 'Reply',
                'body' => (string) ($reply['body'] ?? ''),
                'attachment' => (string) ($reply['attachment'] ?? ''),
                'note_id' => (string) ($reply['note_id'] ?? ''),
            ];

            $repliedIn = ReplyInfolist::repliedIn((string) ($record['inbound'] ?? ''), $sent);

            if ($repliedIn !== '') {
                $events[] = [
                    'at' => $sent,
                    'side' => 'system',
                    'kind' => 'Elapsed',
                    'body' => $repliedIn,
                    'attachment' => '',
                    'note_id' => '',
                ];
            }
        }

        if ($closedWithoutReply) {
            $events[] = [
                'at' => (string) ($record['closed_at'] ?? $record['sent'] ?? ''),
                'side' => 'system',
                'kind' => 'Closed',
                'body' => 'Closed — no reply',
                'attachment' => '',
                'note_id' => '',
            ];
        }

        return $events;
    }
}
