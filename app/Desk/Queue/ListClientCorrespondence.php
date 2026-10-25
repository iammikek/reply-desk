<?php

namespace App\Desk\Queue;

use App\Clock\ClockNote;
use App\Clock\ParsedInbound;
use App\Clock\ParsedSent;
use App\Clock\ReplyClock;
use App\Desk\Notes\NoteStore;
use App\Desk\Notes\StoredNote;
use DateTimeImmutable;
use DateTimeZone;

final class ListClientCorrespondence
{
    public function __construct(private NoteStore $notes) {}

    public function __invoke(string $companyId, string $contactId): ?ClientCorrespondence
    {
        $sent = [];
        $inbound = [];

        foreach ($this->notes->notes() as $note) {
            $parsed = ClockNote::parse($note->body);

            if ($parsed instanceof ParsedSent) {
                $sent[$parsed->closes][] = [$note, $parsed];
            }

            if ($parsed instanceof ParsedInbound && $note->companyId === $companyId && $note->contactId === $contactId) {
                $inbound[] = [$note, $parsed];
            }
        }

        if ($inbound === []) {
            return null;
        }

        usort($inbound, fn (array $left, array $right): int => $left[1]->receipt <=> $right[1]->receipt);

        $events = [];

        foreach ($inbound as [$note, $parsed]) {
            $events[] = $this->event($parsed->receipt, 0, 'Client email', $parsed->summary, ClockNote::correspondence($note->body)['client'], $note->attachmentName, $note->id);

            foreach ($this->replies($sent[$note->id] ?? []) as [$reply, $parsedReply]) {
                $events[] = $this->event($parsedReply->sent, 1, 'Reply', $parsedReply->subject, ClockNote::correspondence($reply->body)['reply'], $reply->attachmentName, $reply->id);
            }
        }

        usort($events, function (array $left, array $right): int {
            $byTime = $left['moment'] <=> $right['moment'];

            return $byTime !== 0 ? $byTime : $left['rank'] <=> $right['rank'];
        });

        /** @var StoredNote $first */
        $first = $inbound[0][0];
        $contact = $first->contactName !== '' ? $first->contactName : $first->contactEmail;

        return new ClientCorrespondence(
            trim($first->companyName.' / '.$contact, ' /'),
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
     * @param  list<array{0: StoredNote, 1: ParsedSent}>  $replies
     * @return list<array{0: StoredNote, 1: ParsedSent}>
     */
    private function replies(array $replies): array
    {
        usort($replies, fn (array $left, array $right): int => $left[1]->sent <=> $right[1]->sent);

        return $replies;
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
        return $moment->setTimezone(new DateTimeZone(ReplyClock::ZONE))->format('Y-m-d H:i');
    }
}
