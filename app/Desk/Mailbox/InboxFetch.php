<?php

declare(strict_types=1);

namespace App\Desk\Mailbox;

final readonly class InboxFetch
{
    /**
     * @param  list<IncomingMessage>  $messages
     */
    public function __construct(
        public array $messages,
        public ?string $uidValidity = null,
    ) {}
}
