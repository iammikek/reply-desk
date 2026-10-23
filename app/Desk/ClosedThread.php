<?php

namespace App\Desk;

use App\Clock\ReplyClock;
use DateTimeImmutable;
use DateTimeZone;

final readonly class ClosedThread
{
    /**
     * @param  list<array{sent: DateTimeImmutable, subject: string, body: string}>  $replies
     */
    public function __construct(
        public string $id,
        public string $client,
        public string $contactEmail,
        public DateTimeImmutable $receipt,
        public string $summary,
        public string $clientEmail,
        public array $replies,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $latest = $this->replies[array_key_last($this->replies)];

        return [
            'id' => $this->id,
            'client' => $this->client,
            'contact_email' => $this->contactEmail,
            'summary' => $this->summary,
            'inbound' => $this->format($this->receipt),
            'sent' => $this->format($latest['sent']),
            'subject' => $latest['subject'],
            'client_email' => $this->clientEmail,
            'replies' => array_map(fn (array $reply): array => [
                'sent' => $this->format($reply['sent']),
                'subject' => $reply['subject'],
                'body' => $reply['body'],
            ], $this->replies),
        ];
    }

    private function format(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone(ReplyClock::ZONE))->format('Y-m-d H:i');
    }
}
