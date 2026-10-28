<?php

declare(strict_types=1);

namespace App\Desk\Mailbox;

use App\Desk\DeskException;

interface Mailbox
{
    public function configured(): bool;

    /**
     * Fetch inbox messages after $cursor. When $expectedUidValidity is set and
     * the folder's UIDVALIDITY no longer matches, the cursor is ignored and the
     * inbox is read from the start.
     */
    public function fetchSince(?string $cursor, ?string $expectedUidValidity = null): InboxFetch;

    public function upsertDraft(string $identity, string $to, string $subject, string $body): string;

    public function deleteDraft(?string $identity): void;

    /**
     * Send via the practice SMTP account and return the Message-ID.
     */
    public function send(string $to, string $subject, string $body, ?string $attachmentPath = null, ?string $attachmentName = null): string;

    /**
     * Probe IMAP and SMTP without changing poll state.
     *
     * @throws DeskException
     */
    public function testConnection(): void;
}
