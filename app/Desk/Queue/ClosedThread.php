<?php

namespace App\Desk\Queue;

use App\Clock\ReplyClock;
use DateTimeImmutable;
use DateTimeZone;

final readonly class ClosedThread
{
    /**
     * @param  list<array{sent: DateTimeImmutable, subject: string, body: string, attachment: ?string, note_id: string}>  $replies
     */
    public function __construct(
        public string $id,
        public string $client,
        public string $contactEmail,
        public DateTimeImmutable $receipt,
        public string $summary,
        public string $clientEmail,
        public array $replies,
        public ?string $attachment = null,
        public string $companyId = '',
        public string $contactId = '',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $latest = $this->replies[array_key_last($this->replies)];

        return [
            'id' => $this->id,
            'company_id' => $this->companyId,
            'contact_id' => $this->contactId,
            'client' => $this->client,
            'contact_email' => $this->contactEmail,
            'summary' => $this->summary,
            'inbound' => $this->format($this->receipt),
            'sent' => $this->format($latest['sent']),
            'subject' => $latest['subject'],
            'client_email' => $this->clientEmail,
            'attachment' => $this->attachment ?? '',
            'replies' => array_map(fn (array $reply): array => [
                'sent' => $this->format($reply['sent']),
                'subject' => $reply['subject'],
                'body' => $reply['body'],
                'attachment' => $reply['attachment'] ?? '',
                'note_id' => $reply['note_id'],
            ], $this->replies),
        ];
    }

    private function format(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone(ReplyClock::zoneName()))->format('Y-m-d H:i');
    }
}
