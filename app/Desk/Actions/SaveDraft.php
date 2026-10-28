<?php

namespace App\Desk\Actions;

use App\Clock\ClockNote;
use App\Clock\ParsedInbound;
use App\Clock\ParsedSent;
use App\Desk\DeskException;
use App\Desk\LogDeskActivity;
use App\Desk\Mailbox\Mailbox;
use App\Desk\Notes\NoteStore;
use App\Desk\Notes\StoredNote;
use App\Models\Draft;

final class SaveDraft
{
    public function __construct(
        private NoteStore $notes,
        private Mailbox $mailbox,
        private LogDeskActivity $log,
    ) {}

    public function __invoke(string $inboundId, string $subject, string $body): void
    {
        $subject = trim($subject);
        $body = ClockNote::pasted($body);

        if ($subject === '' || $body === '' || str_contains($subject, "\n") || str_contains($subject, "\r")) {
            throw new DeskException('A draft needs a subject and a body.');
        }

        $inbound = $this->openInbound($inboundId);
        $attributes = ['subject' => $subject, 'body' => $body];

        if ($this->mailbox->configured()) {
            $existing = Draft::query()->where(Draft::forInbound($inboundId))->first();
            $identity = (string) ($existing?->mailbox_draft_id ?? '');
            $attributes['mailbox_draft_id'] = $this->mailbox->upsertDraft(
                $identity,
                $inbound->contactEmail,
                $subject,
                $body,
            );
        }

        Draft::query()->updateOrCreate(
            Draft::forInbound($inboundId),
            $attributes,
        );

        ($this->log)(
            'draft.saved',
            'Draft saved.',
            inboundId: $inboundId,
            company: $inbound->companyName,
            contact: $inbound->contactName,
            context: [
                'mailbox' => $this->mailbox->configured(),
                'mailbox_draft_id' => $attributes['mailbox_draft_id'] ?? null,
            ],
        );
    }

    private function openInbound(string $inboundId): StoredNote
    {
        $found = null;

        foreach ($this->notes->notes() as $note) {
            $parsed = ClockNote::parse($note->body);

            if ($parsed instanceof ParsedSent && $parsed->closes === $inboundId) {
                throw new DeskException("Inbound {$inboundId} is already closed.");
            }

            if ($note->id === $inboundId && $parsed instanceof ParsedInbound) {
                $found = $note;
            }
        }

        if ($found === null) {
            throw new DeskException("No open inbound {$inboundId}.");
        }

        return $found;
    }
}
