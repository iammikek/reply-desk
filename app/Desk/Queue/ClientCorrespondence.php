<?php

declare(strict_types=1);

namespace App\Desk\Queue;

final readonly class ClientCorrespondence
{
    /**
     * @param  list<array{at: string, kind: string, title: string, body: string, attachment: string, note_id: string}>  $events
     */
    public function __construct(
        public string $client,
        public string $email,
        public array $events,
    ) {}
}
