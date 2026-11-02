<?php

declare(strict_types=1);

namespace App\Desk\Mailbox;

use DateTimeImmutable;

final readonly class FakeMessageOptions
{
    /**
     * @param  list<string>  $references
     */
    public function __construct(
        public ?DateTimeImmutable $date = null,
        public string $messageId = '',
        public string $inReplyTo = '',
        public array $references = [],
        public string $cursor = '',
        public string $htmlBody = '',
    ) {}
}
