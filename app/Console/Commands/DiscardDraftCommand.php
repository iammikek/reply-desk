<?php

namespace App\Console\Commands;

use App\Desk\DiscardDraft;

class DiscardDraftCommand extends ReplyDeskCommand
{
    protected $signature = 'reply:discard-draft {inbound}';

    protected $description = 'Discard the draft for a reply';

    public function handle(DiscardDraft $discardDraft): int
    {
        $discardDraft((string) $this->argument('inbound'));

        return $this->json(['discarded' => true]);
    }
}
