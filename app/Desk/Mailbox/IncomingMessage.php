<?php

namespace App\Desk\Mailbox;

use DateTimeImmutable;

final readonly class IncomingMessage
{
    /**
     * @param  list<string>  $references
     */
    public function __construct(
        public string $messageId,
        public string $fromEmail,
        public string $subject,
        public string $textBody,
        public string $htmlBody,
        public ?DateTimeImmutable $date,
        public string $inReplyTo,
        public array $references,
        public string $cursor,
    ) {}
}
