<?php

namespace App\Filament\Schemas;

use App\Clock\ClockNote;
use App\Clock\Elapsed;
use App\Clock\ReplyClock;
use DateTimeImmutable;
use DateTimeZone;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\View;

final class ReplyInfolist
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
                    'note' => (string) ($record['id'] ?? ''),
                    'events' => self::events($record),
                ])
                ->columnSpanFull(),
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public static function earlierText(array $record): string
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
                $repliedIn = self::repliedIn((string) ($thread['receipt'] ?? ''), (string) ($reply['sent'] ?? ''));

                if ($repliedIn !== '') {
                    $lines[] = $repliedIn;
                }

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

    /**
     * @param  array<string, mixed>  $record
     * @return list<array{at: string, rank: int, kind: string, title: string, detail: string, body: string, attachment: string, note_id: string}>
     */
    public static function events(array $record): array
    {
        $events = [];

        foreach ($record['earlier'] ?? [] as $thread) {
            if (! is_array($thread)) {
                continue;
            }

            $receipt = (string) ($thread['receipt'] ?? '');
            $events[] = self::event($receipt, 0, 'Client email', (string) ($thread['summary'] ?? ''), '', (string) ($thread['client'] ?? ''));

            foreach ($thread['replies'] ?? [] as $reply) {
                if (! is_array($reply)) {
                    continue;
                }

                $sent = (string) ($reply['sent'] ?? '');
                $events[] = self::event($sent, 2, 'Reply', (string) ($reply['subject'] ?? ''), self::repliedIn($receipt, $sent), (string) ($reply['body'] ?? ''));
            }
        }

        $inbound = (string) ($record['inbound'] ?? '');
        $clockStart = (string) ($record['clock_start'] ?? '');
        $mail = ClockNote::correspondence((string) ($record['body'] ?? ''));
        $events[] = self::event($inbound, 0, 'Client email', (string) ($record['summary'] ?? ''), (string) ($record['receipt_source'] ?? ''), $mail['client']);

        if ($clockStart !== '' && $clockStart !== $inbound) {
            $events[] = self::event($clockStart, 1, 'Clock start', '', '', '');
        }

        $due = (string) ($record['reply_due'] ?? '');

        if ($due !== '') {
            $events[] = self::event($due, 3, 'Reply due', '', '', '');
        }

        usort($events, function (array $left, array $right): int {
            $byTime = $left['at'] <=> $right['at'];

            return $byTime !== 0 ? $byTime : $left['rank'] <=> $right['rank'];
        });

        return $events;
    }

    public static function repliedIn(string $receipt, string $sent): string
    {
        $start = self::moment($receipt);
        $end = self::moment($sent);

        if (! $start instanceof DateTimeImmutable || ! $end instanceof DateTimeImmutable) {
            return '';
        }

        $clockStart = app(ReplyClock::class)->schedule($start)->clockStart;
        $elapsed = Elapsed::open($clockStart, $end);

        return $elapsed === '' ? '' : 'Replied in '.$elapsed;
    }

    /**
     * @return array{at: string, rank: int, kind: string, title: string, detail: string, body: string, attachment: string, note_id: string}
     */
    private static function event(string $at, int $rank, string $kind, string $title, string $detail, string $body): array
    {
        return [
            'at' => $at,
            'rank' => $rank,
            'kind' => $kind,
            'title' => $title,
            'detail' => $detail,
            'body' => $body,
            'attachment' => '',
            'note_id' => '',
        ];
    }

    private static function moment(string $value): ?DateTimeImmutable
    {
        $moment = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value, new DateTimeZone(ReplyClock::zoneName()));

        return $moment instanceof DateTimeImmutable ? $moment : null;
    }
}
