<?php

namespace App\Desk;

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
    ) {}

    public function overdueAt(DateTimeImmutable $now): bool
    {
        return $this->replyDue < $now;
    }

    /**
     * @return array<string, bool|string>
     */
    public function toArray(DateTimeImmutable $now): array
    {
        return [
            'id' => $this->id,
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
        ];
    }

    private function format(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone(ReplyClock::ZONE))->format('Y-m-d H:i');
    }
}
