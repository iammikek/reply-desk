<?php

namespace App\Desk\Actions;

use App\Desk\LogDeskActivity;
use App\Desk\Mailbox\Mailbox;
use App\Models\Draft;

final readonly class SaveDraft
{
    public function __construct(
        private FindInbound $findInbound,
        private Mailbox $mailbox,
        private LogDeskActivity $log,
    ) {}

    public function __invoke(string $inboundId, string $subject, string $body): void
    {
        [$subject, $body] = NormalizeReply::draft($subject, $body);
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
            companyId: $inbound->companyId,
            contactId: $inbound->contactId,
            context: [
                'mailbox' => $this->mailbox->configured(),
                'mailbox_draft_id' => $attributes['mailbox_draft_id'] ?? null,
            ],
        );
    }
}
