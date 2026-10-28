<?php

namespace App\Desk\Actions;

use App\Desk\LogDeskActivity;
use App\Desk\Mailbox\Mailbox;
use App\Models\Draft;

final class DiscardDraft
{
    public function __construct(
        private Mailbox $mailbox,
        private LogDeskActivity $log,
    ) {}

    public function __invoke(string $inboundId): void
    {
        $draft = Draft::query()->where(Draft::forInbound($inboundId))->first();
        $mailboxDraftId = $draft?->mailbox_draft_id;

        if ($this->mailbox->configured() && filled($mailboxDraftId)) {
            $this->mailbox->deleteDraft((string) $mailboxDraftId);
        }

        Draft::query()->where(Draft::forInbound($inboundId))->delete();

        ($this->log)(
            'draft.discarded',
            'Draft discarded.',
            inboundId: $inboundId,
            context: [
                'mailbox_draft_id' => $mailboxDraftId,
            ],
        );
    }
}
