<?php

namespace App\Console\Commands;

use App\Clock\ReplyClock;
use App\Desk\DeskException;
use App\Desk\Queue\ListOpenReplies;
use App\Desk\Queue\OpenReply;
use DateTimeImmutable;

class ListOpenRepliesCommand extends ReplyDeskCommand
{
    protected $signature = 'reply:list-open {--now=}';

    protected $description = 'List open replies, with the due time calculated now';

    public function handle(ListOpenReplies $list): int
    {
        try {
            $now = $this->option('now')
                ? $this->moment((string) $this->option('now'))
                : DateTimeImmutable::createFromInterface(now(ReplyClock::zoneName()));

            return $this->json(array_map(
                fn (OpenReply $reply): array => $reply->toArray($now),
                $list($now),
            ));
        } catch (DeskException $exception) {
            return $this->failure($exception);
        }
    }
}
