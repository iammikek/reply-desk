<?php

namespace App\Desk;

use App\Clock\ClockNote;
use App\Clock\ParsedInbound;
use App\Clock\ParsedSent;
use App\Clock\ReplyClock;
use App\Mail\ClientReply;
use DateTimeImmutable;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class SendReply
{
    public function __construct(
        private NoteStore $notes,
        private MarkReplySent $markReplySent,
    ) {}

    public function __invoke(string $inboundId, string $subject, string $body): string
    {
        $subject = trim($subject);
        $body = trim($body);

        if ($subject === '' || $body === '' || str_contains($subject, "\n")) {
            throw new DeskException('A reply needs a subject and a body.');
        }

        $inbound = $this->openInbound($inboundId);

        if (filter_var($inbound->contactEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new DeskException('That contact has no email address.');
        }

        $from = (string) config('mail.from.address');

        if (filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            throw new DeskException('No mailbox is configured.');
        }

        try {
            Mail::to($inbound->contactEmail)->send(new ClientReply($subject, $body));
        } catch (Throwable) {
            throw new DeskException('The mail server refused the message.');
        }

        return ($this->markReplySent)(
            $inboundId,
            DateTimeImmutable::createFromInterface(now(ReplyClock::ZONE)),
            $subject,
            $body,
        );
    }

    private function openInbound(string $inboundId): StoredNote
    {
        $inbound = null;

        foreach ($this->notes->notes() as $note) {
            $parsed = ClockNote::parse($note->body);

            if ($parsed instanceof ParsedSent && $parsed->closes === $inboundId) {
                throw new DeskException("Inbound {$inboundId} is already closed.");
            }

            if ($note->id === $inboundId && $parsed instanceof ParsedInbound) {
                $inbound = $note;
            }
        }

        if (! $inbound instanceof StoredNote) {
            throw new DeskException("No open inbound {$inboundId}.");
        }

        return $inbound;
    }
}
