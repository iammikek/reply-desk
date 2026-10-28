<?php

namespace App\Console\Commands;

use App\Desk\Actions\PollMailbox;
use App\Desk\DeskException;

class PollMailboxCommand extends ReplyDeskCommand
{
    protected $signature = 'reply:poll-mailbox {--force : Run even when polling is disabled or the interval has not elapsed}';

    protected $description = 'Poll the practice mailbox for new inbound mail';

    public function handle(PollMailbox $poll): int
    {
        try {
            $result = $poll(force: (bool) $this->option('force'));

            return $this->json($result);
        } catch (DeskException $exception) {
            return $this->failure($exception);
        }
    }
}
