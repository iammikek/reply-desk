<?php

namespace App\Desk\Queue;

use App\Clock\ClockNote;
use App\Clock\ParsedClosed;
use App\Clock\ParsedInbound;
use App\Clock\ParsedSent;
use App\Clock\ReceiptSource;
use App\Clock\ReplyClock;
use App\Desk\Notes\NoteStore;
use App\Desk\Notes\StoredNote;
use DateTimeImmutable;
use DateTimeZone;

final readonly class ListClientCorrespondence
{
    private const string OUTREACH_SUMMARY = 'Outreach started';

    private const string FOLLOW_UP_SUMMARY = 'Follow-up sent';

    public function __construct(private NoteStore $notes) {}

    public function __invoke(string $companyId, string $contactId): ?ClientCorrespondence
    {
        ['inbound' => $inbound, 'sent' => $sent, 'closed' => $closed] = (new IndexClockNotes($this->notes))($companyId, $contactId);

        if ($inbound === []) {
            return null;
        }

        $threads = [];

        foreach ($inbound as [$note, $parsed]) {
            $piece = $this->thread($note, $parsed, $sent[$note->id] ?? [], $closed[$note->id] ?? null);
            $key = $piece['conversation_key'];

            if (! array_key_exists($key, $threads)) {
                $threads[$key] = $piece;

                continue;
            }

            $threads[$key] = $this->merge($threads[$key], $piece);
        }

        $threads = array_values($threads);

        usort($threads, function (array $left, array $right): int {
            $byStarted = $left['started_moment'] <=> $right['started_moment'];

            if ($byStarted !== 0) {
                return $byStarted;
            }

            return $left['latest_moment'] <=> $right['latest_moment'];
        });

        /** @var StoredNote $first */
        $first = $inbound[0][0];

        return new ClientCorrespondence(
            $first->clientLabel(),
            $first->contactEmail,
            array_map(fn (array $thread): array => [
                'id' => $thread['id'],
                'started_at' => $this->format($thread['started_moment']),
                'latest_at' => $this->format($thread['latest_moment']),
                'subject' => $thread['subject'],
                'summary' => $thread['summary'],
                'outreach' => $thread['outreach'],
                'status' => $thread['status'],
                'events' => array_map(fn (array $event): array => [
                    'at' => $this->format($event['moment']),
                    'side' => $event['side'],
                    'kind' => $event['kind'],
                    'body' => $event['body'],
                    'attachment' => $event['attachment'],
                    'note_id' => $event['note_id'],
                ], $thread['events']),
            ], $threads),
        );
    }

    /**
     * @param  list<array{0: StoredNote, 1: ParsedSent}>  $replies
     * @param  array{0: StoredNote, 1: ParsedClosed}|null  $closure
     * @return array{id: string, started_moment: DateTimeImmutable, latest_moment: DateTimeImmutable, subject: string, summary: string, outreach: bool, status: string, conversation_key: string, events: list<array{moment: DateTimeImmutable, side: string, kind: string, body: string, attachment: string, note_id: string}>}
     */
    private function thread(StoredNote $note, ParsedInbound $parsed, array $replies, ?array $closure): array
    {
        $clientBody = ClockNote::correspondence($note->body)['client'];
        $events = [];
        $events[] = $this->event(
            $parsed->receipt,
            'client',
            'Client email',
            $clientBody !== '' ? $clientBody : $parsed->summary,
            $note->attachmentName,
            $note->id,
        );

        $latest = $parsed->receipt;
        $sortedReplies = IndexClockNotes::sortReplies($replies);
        $subject = trim(ClockNote::subjectOf($note->body));

        foreach ($sortedReplies as [$reply, $parsedReply]) {
            if ($subject === '' && trim($parsedReply->subject) !== '') {
                $subject = trim($parsedReply->subject);
            }

            $events[] = $this->event(
                $parsedReply->sent,
                'owner',
                'Reply',
                ClockNote::correspondence($reply->body)['reply'],
                $reply->attachmentName,
                $reply->id,
            );
            $latest = $parsedReply->sent;
        }

        if ($closure !== null && $sortedReplies === []) {
            $events[] = $this->event($closure[1]->closed, 'system', 'Closed', 'Closed — no reply', $closure[0]->attachmentName, $closure[0]->id);
            $latest = $closure[1]->closed;
        }

        $status = match (true) {
            $sortedReplies !== [] => 'replied',
            $closure !== null => 'closed',
            default => 'open',
        };

        return [
            'id' => $note->id,
            'started_moment' => $parsed->receipt,
            'latest_moment' => $latest,
            'subject' => $this->displaySubject($subject),
            'summary' => $parsed->summary,
            'outreach' => $this->isOutreach($parsed->summary),
            'status' => $status,
            'conversation_key' => $this->conversationKey($note, $parsed, $replies),
            'events' => $events,
        ];
    }

    /**
     * @param  array{id: string, started_moment: DateTimeImmutable, latest_moment: DateTimeImmutable, subject: string, summary: string, outreach: bool, status: string, conversation_key: string, events: list<array{moment: DateTimeImmutable, side: string, kind: string, body: string, attachment: string, note_id: string}>}  $left
     * @param  array{id: string, started_moment: DateTimeImmutable, latest_moment: DateTimeImmutable, subject: string, summary: string, outreach: bool, status: string, conversation_key: string, events: list<array{moment: DateTimeImmutable, side: string, kind: string, body: string, attachment: string, note_id: string}>}  $right
     * @return array{id: string, started_moment: DateTimeImmutable, latest_moment: DateTimeImmutable, subject: string, summary: string, outreach: bool, status: string, conversation_key: string, events: list<array{moment: DateTimeImmutable, side: string, kind: string, body: string, attachment: string, note_id: string}>}
     */
    private function merge(array $left, array $right): array
    {
        $earlierFirst = $left['started_moment'] <= $right['started_moment'] ? $left : $right;
        $latestPiece = $left['latest_moment'] >= $right['latest_moment'] ? $left : $right;

        $events = array_merge($left['events'], $right['events']);
        usort($events, function (array $a, array $b): int {
            $byTime = $a['moment'] <=> $b['moment'];

            if ($byTime !== 0) {
                return $byTime;
            }

            return $this->eventOrder($a['side']) <=> $this->eventOrder($b['side']);
        });

        return [
            'id' => $earlierFirst['id'],
            'started_moment' => $earlierFirst['started_moment'],
            'latest_moment' => $latestPiece['latest_moment'],
            'subject' => $left['subject'] !== '' ? $left['subject'] : $right['subject'],
            'summary' => $earlierFirst['summary'],
            'outreach' => $left['outreach'] && $right['outreach'],
            'status' => $this->mergedStatus($left['status'], $right['status']),
            'conversation_key' => $left['conversation_key'],
            'events' => $events,
        ];
    }

    private function mergedStatus(string $left, string $right): string
    {
        foreach (['open', 'replied', 'closed'] as $status) {
            if ($left === $status || $right === $status) {
                return $status;
            }
        }

        return $left;
    }

    private function eventOrder(string $side): int
    {
        return match ($side) {
            'client' => 0,
            'owner' => 1,
            default => 2,
        };
    }

    /**
     * @param  list<array{0: StoredNote, 1: ParsedSent}>  $replies
     */
    private function conversationKey(StoredNote $note, ParsedInbound $parsed, array $replies): string
    {
        if (in_array($parsed->receiptSource, [ReceiptSource::LinkedIn, ReceiptSource::WhatsApp], true)) {
            return $parsed->receiptSource->value;
        }

        $subject = $this->normalizedSubject(ClockNote::subjectOf($note->body));

        if ($subject === '') {
            $sorted = IndexClockNotes::sortReplies($replies);
            $subject = $sorted === []
                ? ''
                : $this->normalizedSubject($sorted[array_key_last($sorted)][1]->subject);
        }

        if ($subject !== '') {
            return 'mail:'.$subject;
        }

        return 'note:'.$note->id;
    }

    private function displaySubject(string $subject): string
    {
        $subject = trim($subject);

        if ($subject === '') {
            return '';
        }

        do {
            $previous = $subject;
            $subject = trim((string) preg_replace('/^(re|fw|fwd)\s*:\s*/i', '', $subject));
        } while ($subject !== $previous);

        return $subject;
    }

    private function normalizedSubject(string $subject): string
    {
        return mb_strtolower($this->displaySubject($subject));
    }

    private function isOutreach(string $summary): bool
    {
        return in_array(rtrim(trim($summary), '.'), [self::OUTREACH_SUMMARY, self::FOLLOW_UP_SUMMARY], true);
    }

    /**
     * @return array{moment: DateTimeImmutable, side: string, kind: string, body: string, attachment: string, note_id: string}
     */
    private function event(DateTimeImmutable $moment, string $side, string $kind, string $body, ?string $attachment, string $noteId): array
    {
        return [
            'moment' => $moment,
            'side' => $side,
            'kind' => $kind,
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
