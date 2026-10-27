<?php

namespace App\Desk\Actions;

use App\Clock\ClockNote;
use App\Clock\ParsedInbound;
use App\Clock\ParsedSent;
use App\Clock\ReplyClock;
use App\Desk\DeskException;
use App\Desk\Notes\NoteFile;
use App\Desk\Notes\NoteStore;
use App\Desk\Notes\StoredNote;
use App\Models\Draft;
use DateTimeImmutable;
use DateTimeZone;

final class MarkReplySent
{
    public function __construct(private NoteStore $notes) {}

    public function subject(string $inboundId, string $subject): string
    {
        $subject = trim($subject);

        if ($subject !== '') {
            return $subject;
        }

        [$previous, $inboundSubject] = $this->subjects($inboundId);

        return $previous !== '' ? $previous : $inboundSubject;
    }

    public function __invoke(string $inboundId, DateTimeImmutable $sent, string $subject, string $reply = '', ?NoteFile $file = null): string
    {
        $inbound = null;

        foreach ($this->notes->notes() as $note) {
            $parsed = ClockNote::parse($note->body);

            if ($parsed instanceof ParsedSent && $parsed->closes === $inboundId && $this->minute($parsed->sent) === $this->minute($sent)) {
                throw new DeskException('That reply was already recorded at that minute.');
            }

            if ($note->id === $inboundId && $parsed instanceof ParsedInbound) {
                $inbound = $note;
            }
        }

        if (! $inbound instanceof StoredNote) {
            throw new DeskException("No open inbound {$inboundId}.");
        }

        $subject = $this->subject($inboundId, $subject);

        $id = $this->notes->writeNote(
            ClockNote::sent($inboundId, $sent, $subject, $reply),
            $inbound->companyId,
            $inbound->contactId,
            $file,
        );

        Draft::query()->where(Draft::forInbound($inboundId))->delete();

        return $id;
    }

    private function minute(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone(ReplyClock::zoneName()))->format('Y-m-d H:i');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function subjects(string $inboundId): array
    {
        $previous = '';
        $previousAt = null;
        $inboundSubject = '';

        foreach ($this->notes->notes() as $note) {
            $parsed = ClockNote::parse($note->body);

            if ($parsed instanceof ParsedSent && $parsed->closes === $inboundId && $parsed->subject !== '') {
                if (! $previousAt instanceof DateTimeImmutable || $parsed->sent >= $previousAt) {
                    $previousAt = $parsed->sent;
                    $previous = $parsed->subject;
                }
            }

            if ($note->id === $inboundId && $parsed instanceof ParsedInbound) {
                $inboundSubject = ClockNote::subjectOf($note->body);
            }
        }

        return [$previous, $inboundSubject];
    }
}
