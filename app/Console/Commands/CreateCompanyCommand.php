<?php

namespace App\Console\Commands;

use App\Desk\DeskException;
use App\Desk\Notes\NoteStore;

class CreateCompanyCommand extends ReplyDeskCommand
{
    protected $signature = 'reply:create-company {name}';

    protected $description = 'Add a company';

    public function handle(NoteStore $notes): int
    {
        try {
            $company = $notes->createCompany((string) $this->argument('name'));

            return $this->json([
                'id' => $company->id,
                'name' => $company->name,
            ]);
        } catch (DeskException $exception) {
            return $this->failure($exception);
        }
    }
}
