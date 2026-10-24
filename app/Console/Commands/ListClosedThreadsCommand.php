<?php

namespace App\Console\Commands;

use App\Desk\DeskException;
use App\Desk\Queue\ClosedThread;
use App\Desk\Queue\ListClosedThreads;

class ListClosedThreadsCommand extends ReplyDeskCommand
{
    protected $signature = 'reply:list-closed';

    protected $description = 'List closed replies, newest sent time first';

    public function handle(ListClosedThreads $list): int
    {
        try {
            return $this->json(array_map(
                fn (ClosedThread $thread): array => $thread->toArray(),
                $list(),
            ));
        } catch (DeskException $exception) {
            return $this->failure($exception);
        }
    }
}
