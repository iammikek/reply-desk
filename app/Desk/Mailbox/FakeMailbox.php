<?php

namespace App\Desk\Mailbox;

use App\Desk\DeskException;
use DateTimeImmutable;

final class FakeMailbox implements Mailbox
{
    /** @var list<IncomingMessage> */
    public array $inbox = [];

    /** @var array<string, array{to: string, subject: string, body: string}> */
    public array $drafts = [];

    /** @var list<array{to: string, subject: string, body: string, message_id: string}> */
    public array $sent = [];

    public bool $refuseSend = false;

    public bool $refuseFetch = false;

    public bool $refuseTest = false;

    public ?string $uidValidity = '1';

    public int $draftSequence = 0;

    public int $messageSequence = 0;

    public function configured(): bool
    {
        return true;
    }

    public function seed(IncomingMessage $message): void
    {
        $this->inbox[] = $message;
    }

    public function fetchSince(?string $cursor, ?string $expectedUidValidity = null): InboxFetch
    {
        if ($this->refuseFetch) {
            throw new DeskException('Mailbox fetch failed.');
        }

        if (
            $expectedUidValidity !== null
            && $this->uidValidity !== null
            && $expectedUidValidity !== $this->uidValidity
        ) {
            $cursor = null;
        }

        $kept = [];

        foreach ($this->inbox as $message) {
            if ($cursor !== null && $cursor !== '' && strcmp($message->cursor, $cursor) <= 0) {
                continue;
            }

            $kept[] = $message;
        }

        return new InboxFetch($kept, $this->uidValidity);
    }

    public function upsertDraft(string $identity, string $to, string $subject, string $body): string
    {
        if ($identity === '') {
            $this->draftSequence++;
            $identity = 'fake-draft-'.$this->draftSequence;
        }

        $this->drafts[$identity] = [
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
        ];

        return $identity;
    }

    public function deleteDraft(?string $identity): void
    {
        if ($identity === null || $identity === '') {
            return;
        }

        unset($this->drafts[$identity]);
    }

    public function send(string $to, string $subject, string $body, ?string $attachmentPath = null, ?string $attachmentName = null): string
    {
        if ($this->refuseSend) {
            throw new DeskException('The mail server refused the message.');
        }

        $this->messageSequence++;
        $messageId = '<fake-sent-'.$this->messageSequence.'@reply-desk.test>';

        $this->sent[] = [
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
            'message_id' => $messageId,
        ];

        return $messageId;
    }

    public function testConnection(): void
    {
        if ($this->refuseTest) {
            throw new DeskException('Mailbox connection test failed.');
        }
    }

    public static function message(
        string $fromEmail,
        string $subject,
        string $body,
        ?DateTimeImmutable $date = null,
        string $messageId = '',
        string $inReplyTo = '',
        array $references = [],
        string $cursor = '',
        string $htmlBody = '',
    ): IncomingMessage {
        static $seq = 0;
        $seq++;

        return new IncomingMessage(
            messageId: $messageId !== '' ? $messageId : '<fake-in-'.$seq.'@reply-desk.test>',
            fromEmail: $fromEmail,
            subject: $subject,
            textBody: $body,
            htmlBody: $htmlBody,
            date: $date,
            inReplyTo: $inReplyTo,
            references: $references,
            cursor: $cursor !== '' ? $cursor : sprintf('%06d', $seq),
        );
    }
}
