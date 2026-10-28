<?php

namespace App\Desk\Actions;

use App\Clock\ReplyClock;
use App\Desk\DeskException;
use App\Desk\LogDeskActivity;
use App\Desk\Mailbox\Mailbox;
use App\Desk\Notes\NoteFile;
use App\Mail\ClientReply;
use App\Models\Draft;
use App\Models\MailMessageId;
use DateTimeImmutable;
use Illuminate\Support\Facades\Mail;
use Throwable;

final readonly class SendReply
{
    public function __construct(
        private FindInbound $findInbound,
        private MarkReplySent $markReplySent,
        private Mailbox $mailbox,
        private LogDeskActivity $log,
    ) {}

    public function __invoke(string $inboundId, string $subject, string $body, ?NoteFile $file = null): string
    {
        $subject = NormalizeReply::subject($subject);
        $body = NormalizeReply::body($body);
        $inbound = ($this->findInbound)->open($inboundId);
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
        } catch (Throwable $exception) {
            ($this->log)(
                'reply.send_failed',
                'Send refused.',
                inboundId: $inboundId,
                company: $inbound->companyName,
                contact: $inbound->contactName,
            );

            throw $exception instanceof DeskException
                ? $exception
                : new DeskException('The mail server refused the message.');
        }

        if ($this->mailbox->configured()) {
            $this->mailbox->deleteDraft(Draft::findForInbound($inboundId)?->mailbox_draft_id);
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
}
