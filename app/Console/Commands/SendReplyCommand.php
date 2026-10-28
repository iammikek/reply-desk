<?php

namespace App\Console\Commands;

use App\Desk\Actions\SendReply;
use App\Desk\DeskException;

class SendReplyCommand extends ReplyDeskCommand
{
    protected $signature = 'reply:send {inbound} {subject} {body}';

    protected $description = 'Send a reply for an open inbound and mark it sent';

    public function handle(SendReply $sendReply): int
    {
        try {
            $id = $sendReply(
                (string) $this->argument('inbound'),
                (string) $this->argument('subject'),
                (string) $this->argument('body'),
            );

            return $this->json(['sent_id' => $id]);
        } catch (DeskException $exception) {
            return $this->failure($exception);
        }
    }
}
