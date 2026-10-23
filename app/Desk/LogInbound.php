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

        return $this->write($companyParty->id, $contact->id, $receipt, $receiptSource, $summary, $clientEmail, $reply);
    }

    public function record(
        string $companyId,
        string $contactId,
        DateTimeImmutable $receipt,
        ReceiptSource $receiptSource,
        string $summary,
        string $clientEmail = '',
    ): string {
        $this->notes->assertPortal();

        foreach ($this->notes->directory() as $company) {
            if ($company['id'] !== $companyId) {
                continue;
            }

            foreach ($company['contacts'] as $contact) {
                if ($contact['id'] !== $contactId) {
                    continue;
                }

                return $this->write($companyId, $contactId, $receipt, $receiptSource, $summary, $clientEmail);
            }

            throw new DeskException('No contact matched.');
        }

        throw new DeskException('No company matched.');
    }

    private function write(
        string $companyId,
        string $contactId,
        DateTimeImmutable $receipt,
        ReceiptSource $receiptSource,
        string $summary,
        string $clientEmail = '',
        string $reply = '',
    ): string {
        return $this->notes->writeNote(
            ClockNote::withMail(ClockNote::inbound($receipt, $receiptSource, $summary), $clientEmail, $reply),
            $companyId,
            $contactId,
        );
    }
}
