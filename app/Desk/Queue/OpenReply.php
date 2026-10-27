<?php

namespace App\Desk\Queue;

use App\Clock\ReceiptSource;
use App\Clock\ReplyClock;
use DateTimeImmutable;
use DateTimeZone;

final readonly class OpenReply
{
    public function __construct(
        public string $id,
        public string $client,
        public string $summary,
        public string $contactEmail,
        public string $companyId,
        public string $contactId,
        public DateTimeImmutable $inboundAt,
        public DateTimeImmutable $clockStart,
        public DateTimeImmutable $replyDue,
        public ReceiptSource $receiptSource,
        public string $body,
        public ?string $draftSubject = null,
        public ?string $draftBody = null,
        /** @var list<EarlierThread> */
        public array $earlier = [],
    ) {}

    public function overdueAt(DateTimeImmutable $now): bool
    {
        return $this->replyDue < $now;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(DateTimeImmutable $now): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->companyId,
            'contact_id' => $this->contactId,
            'client' => $this->client,
            'summary' => $this->summary,
            'contact_email' => $this->contactEmail,
            'inbound' => $this->format($this->inboundAt),
            'clock_start' => $this->format($this->clockStart),
            'reply_due' => $this->format($this->replyDue),
            'receipt_source' => $this->receiptSource->value,
            'overdue' => $this->overdueAt($now),
            'body' => $this->body,
            'draft_subject' => $this->draftSubject ?? '',
            'draft_body' => $this->draftBody ?? '',
            'has_draft' => $this->draftSubject !== null,
            'earlier' => array_map(fn (EarlierThread $thread): array => [
                'receipt' => $this->format($thread->receipt),
                'summary' => $thread->summary,
                'client' => $thread->clientEmail,
                'replies' => array_map(fn (array $reply): array => [
                    'sent' => $this->format($reply['sent']),
                    'subject' => $reply['subject'],
                    'body' => $reply['body'],
                ], $thread->replies),
            ], $this->earlier),
        ];
    }

    private function format(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone(ReplyClock::zoneName()))->format('Y-m-d H:i');
    }
}
