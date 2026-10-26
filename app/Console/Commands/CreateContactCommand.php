<?php

namespace App\Console\Commands;

use App\Desk\DeskException;
use App\Desk\Notes\NoteStore;

class CreateContactCommand extends ReplyDeskCommand
{
    protected $signature = 'reply:create-contact {company} {name} {email}';

    protected $description = 'Add a contact on one company';

    public function handle(NoteStore $notes): int
    {
        try {
            $company = $notes->findCompany((string) $this->argument('company'));
            $contact = $notes->createContact(
                $company->id,
                (string) $this->argument('name'),
                (string) $this->argument('email'),
            );

            return $this->json([
                'id' => $contact->id,
                'name' => $contact->name,
                'email' => $contact->email,
            ]);
        } catch (DeskException $exception) {
            return $this->failure($exception);
        }
    }
}
