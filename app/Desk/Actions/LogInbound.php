<?php

declare(strict_types=1);

namespace App\Desk\Actions;

use App\Clock\ClockNote;
use App\Clock\ReceiptSource;
use App\Desk\DeskException;
use App\Desk\LogDeskActivity;
use App\Desk\Notes\NoteFile;
use App\Desk\Notes\NoteStore;
use DateTimeImmutable;

final readonly class LogInbound
{
    public function __construct(
        private NoteStore $notes,
        private LogDeskActivity $log,
    ) {}

    /**
     * @param  array<string, mixed>|null  $activityContext
     */
    public function __invoke(
        string $company,
        string $email,
        DateTimeImmutable $receipt,
        ReceiptSource $receiptSource,
        string $summary,
        string $clientEmail = '',
        string $reply = '',
        ?NoteFile $file = null,
        string $subject = '',
        ?array $activityContext = null,
    ): string {
        $this->notes->assertPortal();
        $companyParty = $this->notes->findCompany($company);
        $contact = $this->notes->findContact($email);

        return $this->write(
            $companyParty->id,
            $contact->id,
            $companyParty->name,
            $contact->name !== '' ? $contact->name : $contact->email,
            $receipt,
            $receiptSource,
            $summary,
            $clientEmail,
            $reply,
            $file,
            $subject,
            $activityContext,
        );
    }

    /**
     * @param  array<string, mixed>|null  $activityContext
     */
    public function record(
        string $companyId,
        string $contactId,
        DateTimeImmutable $receipt,
        ReceiptSource $receiptSource,
        string $summary,
        string $clientEmail = '',
        ?NoteFile $file = null,
        string $subject = '',
        ?array $activityContext = null,
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

                return $this->write(
                    $companyId,
                    $contactId,
                    (string) $company['name'],
                    (string) (($contact['name'] !== '' ? $contact['name'] : $contact['email'])),
                    $receipt,
                    $receiptSource,
                    $summary,
                    $clientEmail,
                    file: $file,
                    subject: $subject,
                    activityContext: $activityContext,
                );
            }

            throw new DeskException('No contact matched.');
        }

        throw new DeskException('No company matched.');
    }

    /**
     * @param  array<string, mixed>|null  $activityContext
     */
    private function write(
        string $companyId,
        string $contactId,
        string $companyName,
        string $contactName,
        DateTimeImmutable $receipt,
        ReceiptSource $receiptSource,
        string $summary,
        string $clientEmail = '',
        string $reply = '',
        ?NoteFile $file = null,
        string $subject = '',
        ?array $activityContext = null,
    ): string {
        $id = $this->notes->writeNote(
            ClockNote::withMail(ClockNote::inbound($receipt, $receiptSource, $summary, $subject), $clientEmail, $reply),
            $companyId,
            $contactId,
            $file,
        );

        ($this->log)(
            'inbound.logged',
            'Inbound logged.',
            inboundId: $id,
            noteId: $id,
            company: $companyName,
            contact: $contactName,
            companyId: $companyId,
            contactId: $contactId,
            context: $activityContext,
        );

        return $id;
    }
}
