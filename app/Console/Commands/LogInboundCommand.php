<?php

namespace App\Console\Commands;

use App\Desk\Actions\InboundLogInput;
use App\Desk\Actions\LogInbound;
use App\Desk\DeskException;

class LogInboundCommand extends ReplyDeskCommand
{
    protected $signature = 'reply:log-inbound {company} {email} {receipt} {source} {summary} {client?} {--subject=}';

    protected $description = 'Record one inbound client email';

    public function handle(LogInbound $logInbound): int
    {
        try {
            $id = $logInbound(
                (string) $this->argument('company'),
                (string) $this->argument('email'),
                new InboundLogInput(
                    receipt: $this->moment((string) $this->argument('receipt')),
                    receiptSource: $this->source((string) $this->argument('source')),
                    summary: (string) $this->argument('summary'),
                    clientEmail: (string) ($this->argument('client') ?? ''),
                    subject: (string) $this->option('subject'),
                ),
            );

            return $this->json(['id' => $id]);
        } catch (DeskException $exception) {
            return $this->failure($exception);
        }
    }
}
