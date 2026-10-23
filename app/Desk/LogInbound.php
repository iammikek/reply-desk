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
    ): string {
        $this->notes->assertPortal();
        $companyParty = $this->notes->findCompany($company);
        $contact = $this->notes->findContact($email);

        return $this->notes->writeNote(
            ClockNote::inbound($receipt, $receiptSource, $summary),
            $companyParty->id,
            $contact->id,
        );
    }
}
