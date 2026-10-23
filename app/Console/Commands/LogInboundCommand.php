<?php

namespace App\Console\Commands;

use App\Desk\DeskException;
use App\Desk\LogInbound;

class LogInboundCommand extends ReplyDeskCommand
{
    protected $signature = 'reply:log-inbound {company} {email} {receipt} {source} {summary}';

    protected $description = 'Record one inbound client email';

    public function handle(LogInbound $logInbound): int
    {
        try {
            $id = $logInbound(
                (string) $this->argument('company'),
                (string) $this->argument('email'),
                $this->moment((string) $this->argument('receipt')),
                $this->source((string) $this->argument('source')),
                (string) $this->argument('summary'),
            );

            return $this->json(['id' => $id]);
        } catch (DeskException $exception) {
            return $this->failure($exception);
        }
    }
}
