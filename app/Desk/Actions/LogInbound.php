<?php

declare(strict_types=1);

namespace App\Desk\Actions;

use App\Clock\ClockNote;
use App\Desk\DeskActivityEntry;
use App\Desk\DeskException;
use App\Desk\LogDeskActivity;
use App\Desk\Notes\NoteStore;

final readonly class LogInbound
{
    public function __construct(
        private NoteStore $notes,
        private LogDeskActivity $log,
    ) {}

    public function __invoke(
        string $company,
        string $email,
        InboundLogInput $input,
    ): string {
        $this->notes->assertPortal();
        $companyParty = $this->notes->findCompany($company);
        $contact = $this->notes->findContact($email);

        return $this->write(
            $companyParty->id,
            $contact->id,
            $companyParty->name,
            $contact->name !== '' ? $contact->name : $contact->email,
            $input,
        );
    }

    public function record(
        string $companyId,
        string $contactId,
        InboundLogInput $input,
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
                    $input,
                );
            }

            throw new DeskException('No contact matched.');
        }

        throw new DeskException('No company matched.');
    }

    private function write(
        string $companyId,
        string $contactId,
        string $companyName,
        string $contactName,
        InboundLogInput $input,
    ): string {
        $id = $this->notes->writeNote(
            ClockNote::withMail(
                ClockNote::inbound($input->receipt, $input->receiptSource, $input->summary, $input->subject),
                $input->clientEmail,
                $input->reply,
            ),
            $companyId,
            $contactId,
            $input->file,
        );

        ($this->log)(new DeskActivityEntry(
            action: 'inbound.logged',
            summary: 'Inbound logged.',
            inboundId: $id,
            noteId: $id,
            company: $companyName,
            contact: $contactName,
            companyId: $companyId,
            contactId: $contactId,
            context: $input->activityContext,
        ));

        return $id;
    }
}
