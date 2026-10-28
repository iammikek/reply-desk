<?php

namespace App\Desk\Actions;

use App\Clock\ClockNote;
use App\Clock\ParsedInbound;
use App\Clock\ParsedSent;
use App\Clock\ReplyClock;
use App\Desk\DeskException;
use App\Desk\LogDeskActivity;
use App\Desk\Mailbox\Mailbox;
use App\Desk\Notes\NoteFile;
use App\Desk\Notes\NoteStore;
use App\Desk\Notes\StoredNote;
use App\Mail\ClientReply;
use App\Models\Draft;
use App\Models\MailMessageId;
use DateTimeImmutable;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class SendReply
{
    public function __construct(
        private NoteStore $notes,
        private MarkReplySent $markReplySent,
        private Mailbox $mailbox,
        private LogDeskActivity $log,
    ) {}

    public function __invoke(string $inboundId, string $subject, string $body, ?NoteFile $file = null): string
    {
        $subject = trim($subject);
        $body = ClockNote::pasted($body);

        if ($body === '') {
            throw new DeskException('A reply needs a body.');
        }

        if (str_contains($subject, "\n") || str_contains($subject, "\r")) {
            throw new DeskException('Subject must be one line.');
        }

        $inbound = $this->openInbound($inboundId);
        $subject = $this->markReplySent->subject($inboundId, $subject);

        if (filter_var($inbound->contactEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new DeskException('That contact has no email address.');
        }

        try {
            if ($this->mailbox->configured()) {
                $messageId = $this->mailbox->send(
                    $inbound->contactEmail,
                    $subject,
                    $body,
                    $file?->path,
                    $file?->name,
                );
            } else {
                $from = (string) config('mail.from.address');

                if (filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
                    throw new DeskException('No mailbox is configured.');
                }

                Mail::to($inbound->contactEmail)->send(new ClientReply($subject, $body, $file));
                $messageId = null;
            }
        } catch (DeskException $exception) {
            ($this->log)(
                'reply.send_failed',
                'Send refused.',
                inboundId: $inboundId,
                company: $inbound->companyName,
                contact: $inbound->contactName,
            );

            throw $exception;
        } catch (Throwable) {
            ($this->log)(
                'reply.send_failed',
                'Send refused.',
                inboundId: $inboundId,
                company: $inbound->companyName,
                contact: $inbound->contactName,
            );

            throw new DeskException('The mail server refused the message.');
        }

        if ($this->mailbox->configured()) {
            $draft = Draft::query()->where(Draft::forInbound($inboundId))->first();
            $this->mailbox->deleteDraft($draft?->mailbox_draft_id);
        }

        $sentId = ($this->markReplySent)(
            $inboundId,
            DateTimeImmutable::createFromInterface(now(ReplyClock::zoneName())),
            $subject,
            $body,
            $file,
        );

        if (is_string($messageId) && $messageId !== '') {
            MailMessageId::remember($messageId, $sentId, 'sent');
        }

        ($this->log)(
            'reply.sent',
            'Reply sent.',
            inboundId: $inboundId,
            noteId: $sentId,
            company: $inbound->companyName,
            contact: $inbound->contactName,
            context: [
                'message_id' => $messageId,
                'mailbox' => $this->mailbox->configured(),
            ],
        );

        return $sentId;
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
