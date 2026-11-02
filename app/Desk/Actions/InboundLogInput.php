<?php

declare(strict_types=1);

namespace App\Desk\Actions;

use App\Clock\ReceiptSource;
use App\Desk\Notes\NoteFile;
use DateTimeImmutable;

final readonly class InboundLogInput
{
    /**
     * @param  array<string, mixed>|null  $activityContext
     */
    public function __construct(
        public DateTimeImmutable $receipt,
        public ReceiptSource $receiptSource,
        public string $summary,
        public string $clientEmail = '',
        public string $reply = '',
        public ?NoteFile $file = null,
        public string $subject = '',
        public ?array $activityContext = null,
    ) {}
}
