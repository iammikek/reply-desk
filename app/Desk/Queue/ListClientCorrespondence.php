<?php

namespace App\Desk\Queue;

use App\Clock\ClockNote;
use App\Clock\ReplyClock;
use App\Desk\Notes\NoteStore;
use App\Desk\Notes\StoredNote;
use DateTimeImmutable;
use DateTimeZone;

final readonly class ListClientCorrespondence
{
    public function __construct(private NoteStore $notes) {}

    public function __invoke(string $companyId, string $contactId): ?ClientCorrespondence
    {
        ['inbound' => $inbound, 'sent' => $sent] = (new IndexClockNotes($this->notes))($companyId, $contactId);

        if ($inbound === []) {
            return null;
        }

        usort($inbound, fn (array $left, array $right): int => $left[1]->receipt <=> $right[1]->receipt);

        $events = [];

        foreach ($inbound as [$note, $parsed]) {
            $events[] = $this->event($parsed->receipt, 0, 'Client email', $parsed->summary, ClockNote::correspondence($note->body)['client'], $note->attachmentName, $note->id);

            foreach (IndexClockNotes::sortReplies($sent[$note->id] ?? []) as [$reply, $parsedReply]) {
                $events[] = $this->event($parsedReply->sent, 1, 'Reply', $parsedReply->subject, ClockNote::correspondence($reply->body)['reply'], $reply->attachmentName, $reply->id);
            }
        }

        usort($events, function (array $left, array $right): int {
            $byTime = $left['moment'] <=> $right['moment'];

            return $byTime !== 0 ? $byTime : $left['rank'] <=> $right['rank'];
        });

        /** @var StoredNote $first */
        $first = $inbound[0][0];

        return new ClientCorrespondence(
            $first->clientLabel(),
            $first->contactEmail,
            array_map(fn (array $event): array => [
                'at' => $this->format($event['moment']),
                'kind' => $event['kind'],
                'title' => $event['title'],
                'body' => $event['body'],
                'attachment' => $event['attachment'],
                'note_id' => $event['note_id'],
            ], $events),
        );
    }

    /**
     * @return array{moment: DateTimeImmutable, rank: int, kind: string, title: string, body: string, attachment: string, note_id: string}
     */
    private function event(DateTimeImmutable $moment, int $rank, string $kind, string $title, string $body, ?string $attachment, string $noteId): array
    {
        return [
            'moment' => $moment,
            'rank' => $rank,
            'kind' => $kind,
            'title' => $title,
            'body' => $body,
            'attachment' => $attachment ?? '',
            'note_id' => $noteId,
        ];
    }

    private function format(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone(ReplyClock::zoneName()))->format('Y-m-d H:i');
    }
}
