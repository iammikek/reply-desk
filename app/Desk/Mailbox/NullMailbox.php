<?php

namespace App\Desk\Mailbox;

use App\Desk\DeskException;

final class NullMailbox implements Mailbox
{
    public function configured(): bool
    {
        return false;
    }

    public function fetchSince(?string $cursor): array
    {
        return [];
    }

    public function upsertDraft(string $identity, string $to, string $subject, string $body): string
    {
        throw new DeskException('No mailbox is configured.');
    }

    public function deleteDraft(?string $identity): void
    {
        //
    }

    public function send(string $to, string $subject, string $body, ?string $attachmentPath = null, ?string $attachmentName = null): string
    {
        throw new DeskException('No mailbox is configured.');
    }
}
