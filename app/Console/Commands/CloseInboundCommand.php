<?php

namespace App\Console\Commands;

use App\Desk\Actions\CloseInbound;
use App\Desk\DeskException;

class CloseInboundCommand extends ReplyDeskCommand
{
    protected $signature = 'reply:close {inbound} {--at= : Closed time, Y-m-d H:i Europe/London. Defaults to now.}';

    protected $description = 'Close an open inbound without sending a reply';

    public function handle(CloseInbound $closeInbound): int
    {
        try {
            $at = $this->option('at');
            $id = $closeInbound(
                (string) $this->argument('inbound'),
                is_string($at) && $at !== '' ? $this->moment($at) : null,
            );

            return $this->json(['id' => $id]);
        } catch (DeskException $exception) {
            return $this->failure($exception);
        }
    }
}
