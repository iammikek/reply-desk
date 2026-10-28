<?php

declare(strict_types=1);

namespace App\Desk\Mailbox;

interface Mailbox
{
    public function configured(): bool;

    /**
     * @return list<IncomingMessage>
     */
    public function fetchSince(?string $cursor): array;

    public function upsertDraft(string $identity, string $to, string $subject, string $body): string;

    public function deleteDraft(?string $identity): void;

    /**
     * Send via the practice SMTP account and return the Message-ID.
     */
    public function send(string $to, string $subject, string $body, ?string $attachmentPath = null, ?string $attachmentName = null): string;
}
