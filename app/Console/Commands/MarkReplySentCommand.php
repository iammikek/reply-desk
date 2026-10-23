<?php

namespace App\Console\Commands;

use App\Desk\DeskException;
use App\Desk\MarkReplySent;

class MarkReplySentCommand extends ReplyDeskCommand
{
    protected $signature = 'reply:mark-sent {inbound} {sent} {subject}';

    protected $description = 'Record that a reply has already been sent';

    public function handle(MarkReplySent $markReplySent): int
    {
        try {
            $id = $markReplySent(
                (string) $this->argument('inbound'),
                $this->moment((string) $this->argument('sent')),
                (string) $this->argument('subject'),
            );

            return $this->json(['id' => $id]);
        } catch (DeskException $exception) {
            return $this->failure($exception);
        }
    }
}
