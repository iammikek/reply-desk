<?php

namespace App\Console\Commands;

use App\Desk\Actions\MarkReplySent;
use App\Desk\DeskException;

class MarkReplySentCommand extends ReplyDeskCommand
{
    protected $signature = 'reply:mark-sent {inbound} {sent} {reply?} {--subject=}';

    protected $description = 'Record that a reply has already been sent';

    public function handle(MarkReplySent $markReplySent): int
    {
        try {
            $id = $markReplySent(
                (string) $this->argument('inbound'),
                $this->moment((string) $this->argument('sent')),
                (string) $this->option('subject'),
                (string) ($this->argument('reply') ?? ''),
            );

            return $this->json(['id' => $id]);
        } catch (DeskException $exception) {
            return $this->failure($exception);
        }
    }
}
