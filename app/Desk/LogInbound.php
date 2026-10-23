<?php

namespace App\Desk;

use App\Clock\ClockNote;
use App\Clock\ReceiptSource;
use DateTimeImmutable;

final class LogInbound
{
    public function __construct(private NoteStore $notes) {}

    public function __invoke(
        string $company,
        string $email,
        DateTimeImmutable $receipt,
        ReceiptSource $receiptSource,
        string $summary,
        string $clientEmail = '',
        string $reply = '',
    ): string {
        $this->notes->assertPortal();
        $companyParty = $this->notes->findCompany($company);
        $contact = $this->notes->findContact($email);

        return $this->notes->writeNote(
            ClockNote::withMail(ClockNote::inbound($receipt, $receiptSource, $summary), $clientEmail, $reply),
            $companyParty->id,
            $contact->id,
        );
    }
}
