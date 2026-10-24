<?php

namespace App\Console\Commands;

use App\Desk\Actions\SaveDraft;
use App\Desk\DeskException;

class SaveDraftCommand extends ReplyDeskCommand
{
    protected $signature = 'reply:save-draft {inbound} {subject} {body}';

    protected $description = 'Save a draft for an open reply';

    public function handle(SaveDraft $saveDraft): int
    {
        try {
            $saveDraft(
                (string) $this->argument('inbound'),
                (string) $this->argument('subject'),
                (string) $this->argument('body'),
            );

            return $this->json(['saved' => true]);
        } catch (DeskException $exception) {
            return $this->failure($exception);
        }
    }
}
