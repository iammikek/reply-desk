<?php

declare(strict_types=1);

namespace App\Desk\Queue;

final readonly class ClientCorrespondence
{
    /**
     * @param  list<array{id: string, started_at: string, latest_at: string, subject: string, summary: string, outreach: bool, status: string, events: list<array{at: string, side: string, kind: string, body: string, attachment: string, note_id: string}>}>  $threads
     */
    public function __construct(
        public string $client,
        public string $email,
        public array $threads,
    ) {}

    /**
     * @return list<array{id: string, started_at: string, latest_at: string, subject: string, summary: string, outreach: bool, status: string, events: list<array{at: string, side: string, kind: string, body: string, attachment: string, note_id: string}>}>
     */
    public function replies(): array
    {
        return array_values(array_filter(
            $this->threads,
            fn (array $thread): bool => ! $thread['outreach'],
        ));
    }

    /**
     * @return list<array{id: string, started_at: string, latest_at: string, subject: string, summary: string, outreach: bool, status: string, events: list<array{at: string, side: string, kind: string, body: string, attachment: string, note_id: string}>}>
     */
    public function outreach(): array
    {
        return array_values(array_filter(
            $this->threads,
            fn (array $thread): bool => $thread['outreach'],
        ));
    }
}
