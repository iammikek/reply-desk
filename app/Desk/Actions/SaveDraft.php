<?php

namespace App\Desk\Actions;

use App\Clock\ClockNote;
use App\Desk\DeskException;
use App\Desk\LogDeskActivity;
use App\Desk\Mailbox\Mailbox;
use App\Models\Draft;

final class SaveDraft
{
    public function __construct(
        private FindInbound $findInbound,
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

        $inbound = ($this->findInbound)->open($inboundId);
        $attributes = ['subject' => $subject, 'body' => $body];

        if ($this->mailbox->configured()) {
            $existing = Draft::findForInbound($inboundId);
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
}
