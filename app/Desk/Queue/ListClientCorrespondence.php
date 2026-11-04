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

    private const int SUBJECT_MAX_LENGTH = 80;

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

        $threads = $this->joinMailThreads(array_values($threads));

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
     * @return array{id: string, started_moment: DateTimeImmutable, latest_moment: DateTimeImmutable, subject: string, subject_key: string, summary: string, outreach: bool, status: string, channel: string, conversation_key: string, events: list<array{moment: DateTimeImmutable, side: string, kind: string, body: string, attachment: string, note_id: string}>}
     */
    private function thread(StoredNote $note, ParsedInbound $parsed, array $replies, ?array $closure): array
    {
        $outreach = $this->isOutreach($parsed->summary);
        $sortedReplies = IndexClockNotes::sortReplies($replies);
        $timeline = $this->threadTimeline($note, $parsed, $sortedReplies, $closure);
        [$channel, $subjectKey, $conversationKey] = $this->conversation($note, $parsed, $replies);

        return [
            'id' => $note->id,
            'started_moment' => $parsed->receipt,
            'latest_moment' => $timeline['latest'],
            'subject' => $this->isUsableSubject($timeline['subject']) ? $this->displaySubject($timeline['subject']) : '',
            'subject_key' => $subjectKey,
            'summary' => $parsed->summary,
            'outreach' => $outreach,
            'status' => $this->threadStatus($sortedReplies, $closure),
            'channel' => $channel,
            'conversation_key' => $conversationKey,
            'events' => $timeline['events'],
        ];
    }

    /**
     * @param  list<array{0: StoredNote, 1: ParsedSent}>  $replies
     * @param  array{0: StoredNote, 1: ParsedClosed}|null  $closure
     * @return array{events: list<array{moment: DateTimeImmutable, side: string, kind: string, body: string, attachment: string, note_id: string}>, latest: DateTimeImmutable, subject: string}
     */
    private function threadTimeline(StoredNote $note, ParsedInbound $parsed, array $replies, ?array $closure): array
    {
        $events = $this->inboundEvents($note, $parsed);
        $subject = trim(ClockNote::subjectOf($note->body));
        $latest = $parsed->receipt;

        foreach ($replies as [$reply, $parsedReply]) {
            $replySubject = trim($parsedReply->subject);

            if ($subject === '' && $replySubject !== '') {
                $subject = $replySubject;
            }

            $owner = $this->ownerEvent($reply, $parsedReply);

            if ($owner !== null) {
                $events[] = $owner;
            }

            $latest = $parsedReply->sent;
        }

        if ($closure !== null && $replies === []) {
            $events[] = $this->event($closure[1]->closed, 'system', 'Closed', 'Closed — no reply', $closure[0]->attachmentName, $closure[0]->id);
            $latest = $closure[1]->closed;
        }

        return [
            'events' => $events,
            'latest' => $latest,
            'subject' => $subject,
        ];
    }

    /**
     * @return list<array{moment: DateTimeImmutable, side: string, kind: string, body: string, attachment: string, note_id: string}>
     */
    private function inboundEvents(StoredNote $note, ParsedInbound $parsed): array
    {
        $clientBody = ClockNote::correspondence($note->body)['client'];

        if ($clientBody === '') {
            return [];
        }

        return [$this->event($parsed->receipt, 'client', 'Client email', $clientBody, $note->attachmentName, $note->id)];
    }

    /**
     * @return array{moment: DateTimeImmutable, side: string, kind: string, body: string, attachment: string, note_id: string}|null
     */
    private function ownerEvent(StoredNote $reply, ParsedSent $parsedReply): ?array
    {
        $body = ClockNote::correspondence($reply->body)['reply'];
        $subject = trim($parsedReply->subject);

        // Long "subjects" are usually the pasted reply body mis-filed as Subject.
        if ($body === '' && ! $this->isUsableSubject($subject)) {
            $body = $subject;
        }

        if ($body === '' && ($reply->attachmentName ?? '') === '') {
            return null;
        }

        return $this->event($parsedReply->sent, 'owner', 'Reply', $body, $reply->attachmentName, $reply->id);
    }

    /**
     * @param  list<array{0: StoredNote, 1: ParsedSent}>  $replies
     * @param  array{0: StoredNote, 1: ParsedClosed}|null  $closure
     */
    private function threadStatus(array $replies, ?array $closure): string
    {
        return match (true) {
            $replies !== [] => 'replied',
            $closure !== null => 'closed',
            default => 'open',
        };
    }

    /**
     * @param  array{id: string, started_moment: DateTimeImmutable, latest_moment: DateTimeImmutable, subject: string, subject_key: string, summary: string, outreach: bool, status: string, channel: string, conversation_key: string, events: list<array{moment: DateTimeImmutable, side: string, kind: string, body: string, attachment: string, note_id: string}>}  $left
     * @param  array{id: string, started_moment: DateTimeImmutable, latest_moment: DateTimeImmutable, subject: string, subject_key: string, summary: string, outreach: bool, status: string, channel: string, conversation_key: string, events: list<array{moment: DateTimeImmutable, side: string, kind: string, body: string, attachment: string, note_id: string}>}  $right
     * @return array{id: string, started_moment: DateTimeImmutable, latest_moment: DateTimeImmutable, subject: string, subject_key: string, summary: string, outreach: bool, status: string, channel: string, conversation_key: string, events: list<array{moment: DateTimeImmutable, side: string, kind: string, body: string, attachment: string, note_id: string}>}
     */
    private function merge(array $left, array $right): array
    {
        $earlierFirst = $left['started_moment'] <= $right['started_moment'] ? $left : $right;
        $latestPiece = $left['latest_moment'] >= $right['latest_moment'] ? $left : $right;
        $subjectKeeper = $this->preferSubject($left, $right);

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
            'subject' => $subjectKeeper['subject'],
            'subject_key' => $subjectKeeper['subject_key'],
            'summary' => $earlierFirst['summary'],
            'outreach' => $left['outreach'] && $right['outreach'],
            'status' => $this->mergedStatus($left['status'], $right['status']),
            'channel' => $earlierFirst['channel'],
            'conversation_key' => $subjectKeeper['conversation_key'],
            'events' => $events,
        ];
    }

    /**
     * @param  array{subject: string, subject_key: string, conversation_key: string, outreach: bool}  $left
     * @param  array{subject: string, subject_key: string, conversation_key: string, outreach: bool}  $right
     * @return array{subject: string, subject_key: string, conversation_key: string}
     */
    private function preferSubject(array $left, array $right): array
    {
        if ($left['outreach'] && ! $right['outreach'] && $right['subject_key'] !== '') {
            return $right;
        }

        if ($right['outreach'] && ! $left['outreach'] && $left['subject_key'] !== '') {
            return $left;
        }

        if ($left['subject_key'] !== '' && $right['subject_key'] === '') {
            return $left;
        }

        if ($right['subject_key'] !== '' && $left['subject_key'] === '') {
            return $right;
        }

        if ($left['subject'] !== '') {
            return $left;
        }

        return $right;
    }

    /**
     * @param  list<array{id: string, started_moment: DateTimeImmutable, latest_moment: DateTimeImmutable, subject: string, subject_key: string, summary: string, outreach: bool, status: string, channel: string, conversation_key: string, events: list<array{moment: DateTimeImmutable, side: string, kind: string, body: string, attachment: string, note_id: string}>}>  $threads
     * @return list<array{id: string, started_moment: DateTimeImmutable, latest_moment: DateTimeImmutable, subject: string, subject_key: string, summary: string, outreach: bool, status: string, channel: string, conversation_key: string, events: list<array{moment: DateTimeImmutable, side: string, kind: string, body: string, attachment: string, note_id: string}>}>
     */
    private function joinMailThreads(array $threads): array
    {
        $joined = [];

        foreach ($threads as $thread) {
            $matched = false;

            foreach ($joined as $index => $existing) {
                if (! $this->shouldJoin($existing, $thread)) {
                    continue;
                }

                $joined[$index] = $this->merge($existing, $thread);
                $matched = true;

                break;
            }

            if (! $matched) {
                $joined[] = $thread;
            }
        }

        return $this->absorbLoneMailOrphans($joined);
    }

    /**
     * @param  array{channel: string, subject_key: string, outreach: bool, started_moment: DateTimeImmutable, latest_moment: DateTimeImmutable}  $left
     * @param  array{channel: string, subject_key: string, outreach: bool, started_moment: DateTimeImmutable, latest_moment: DateTimeImmutable}  $right
     */
    private function shouldJoin(array $left, array $right): bool
    {
        if ($left['channel'] !== 'mail' || $right['channel'] !== 'mail') {
            return false;
        }

        if ($left['subject_key'] !== '' && $left['subject_key'] === $right['subject_key']) {
            return true;
        }

        if ($this->subjectsRelated($left['subject_key'], $right['subject_key'])) {
            return true;
        }

        return false;
    }

    /**
     * @param  list<array{id: string, started_moment: DateTimeImmutable, latest_moment: DateTimeImmutable, subject: string, subject_key: string, summary: string, outreach: bool, status: string, channel: string, conversation_key: string, events: list<array{moment: DateTimeImmutable, side: string, kind: string, body: string, attachment: string, note_id: string}>}>  $threads
     * @return list<array{id: string, started_moment: DateTimeImmutable, latest_moment: DateTimeImmutable, subject: string, subject_key: string, summary: string, outreach: bool, status: string, channel: string, conversation_key: string, events: list<array{moment: DateTimeImmutable, side: string, kind: string, body: string, attachment: string, note_id: string}>}>
     */
    private function absorbLoneMailOrphans(array $threads): array
    {
        $mailIndexes = [];
        $anchoredIndexes = [];

        foreach ($threads as $index => $thread) {
            if ($thread['channel'] !== 'mail') {
                continue;
            }

            $mailIndexes[] = $index;

            if ($thread['subject_key'] !== '' && ! $thread['outreach']) {
                $anchoredIndexes[] = $index;
            }
        }

        if (count($anchoredIndexes) !== 1) {
            return $threads;
        }

        $anchorIndex = $anchoredIndexes[0];
        $anchor = $threads[$anchorIndex];

        foreach ($mailIndexes as $index) {
            if ($index === $anchorIndex || ! $threads[$index]['outreach']) {
                continue;
            }

            $anchor = $this->merge($anchor, $threads[$index]);
            unset($threads[$index]);
        }

        $threads[$anchorIndex] = $anchor;

        return array_values($threads);
    }

    private function subjectsRelated(string $left, string $right): bool
    {
        if ($left === '' || $right === '') {
            return false;
        }

        if ($left === $right) {
            return true;
        }

        $words = function (string $subject): array {
            $parts = preg_split('/[^a-z0-9]+/', $subject) ?: [];

            return array_values(array_filter(
                $parts,
                fn (string $word): bool => strlen($word) >= 4,
            ));
        };

        return array_intersect($words($left), $words($right)) !== [];
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
     * @return array{0: string, 1: string, 2: string}
     */
    private function conversation(StoredNote $note, ParsedInbound $parsed, array $replies): array
    {
        if (in_array($parsed->receiptSource, [ReceiptSource::LinkedIn, ReceiptSource::WhatsApp], true)) {
            $channel = $parsed->receiptSource->value;

            return [$channel, $channel, $channel];
        }

        $subject = $this->usableSubjectKey(ClockNote::subjectOf($note->body));

        if ($subject === '') {
            $sorted = IndexClockNotes::sortReplies($replies);
            $subject = $sorted === []
                ? ''
                : $this->usableSubjectKey($sorted[array_key_last($sorted)][1]->subject);
        }

        if ($subject !== '') {
            return ['mail', $subject, 'mail:'.$subject];
        }

        return ['mail', '', 'note:'.$note->id];
    }

    private function usableSubjectKey(string $subject): string
    {
        $display = $this->displaySubject($subject);

        if (! $this->isUsableSubject($display)) {
            return '';
        }

        return mb_strtolower($display);
    }

    private function isUsableSubject(string $subject): bool
    {
        $subject = $this->displaySubject($subject);

        return $subject !== '' && mb_strlen($subject) <= self::SUBJECT_MAX_LENGTH;
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
