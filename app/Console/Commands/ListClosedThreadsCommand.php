<?php

namespace App\Console\Commands;

use App\Desk\ClosedThread;
use App\Desk\DeskException;
use App\Desk\ListClosedThreads;

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
