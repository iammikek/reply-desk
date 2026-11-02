<?php

namespace App\Desk\Notes;

use App\Desk\DeskException;
use App\Models\Company;
use App\Models\Contact;
use Illuminate\Database\QueryException;

final class DatabaseParties
{
    public function findCompany(string $name): Party
    {
        $name = trim($name);

        if ($name === '') {
            throw new DeskException('A company is required.');
        }

        $exact = Company::query()->where('name', $name)->orderBy('id')->get();

        if ($exact->count() === 1) {
            return $this->companyParty($exact->first());
        }

        if ($exact->count() > 1) {
            throw new DeskException('More than one company matched ('.$exact->pluck('id')->implode(', ').'). Stop and choose one.');
        }

        $tokens = array_values(array_filter(
            preg_split('/\s+/', $name) ?: [],
            fn (string $token): bool => $token !== '',
        ));

        if ($tokens === []) {
            throw new DeskException('No company matched.');
        }

        $query = Company::query()->orderBy('id');

        foreach ($tokens as $token) {
            $query->where('name', 'like', '%'.addcslashes($token, '%_\\').'%');
        }

        $matched = $query->get();

        if ($matched->count() === 1) {
            return $this->companyParty($matched->first());
        }

        if ($matched->count() > 1) {
            throw new DeskException('More than one company matched ('.$matched->pluck('id')->implode(', ').'). Stop and choose one.');
        }

        throw new DeskException('No company matched.');
    }

    public function findContact(string $email): Party
    {
        $email = strtolower(trim($email));

        if ($email === '') {
            throw new DeskException('A contact is required.');
        }

        $rows = Contact::query()->where('email', $email)->orderBy('id')->get();

        if ($rows->count() === 0) {
            throw new DeskException('No contact matched.');
        }

        if ($rows->count() > 1) {
            throw new DeskException('More than one contact matched ('.$rows->pluck('id')->implode(', ').'). Stop and choose one.');
        }

        return $this->contactParty($rows->first());
    }

    public function createCompany(string $name): Party
    {
        $name = trim($name);

        if ($name === '') {
            throw new DeskException('A company is required.');
        }

        $existing = Company::query()->where('name', $name)->orderBy('id')->get();

        if ($existing->count() > 1) {
            throw new DeskException('More than one company matched ('.$existing->pluck('id')->implode(', ').'). Stop and choose one.');
        }

        if ($existing->count() === 1) {
            throw new DeskException('That company is already there.');
        }

        try {
            $company = Company::query()->create(['name' => $name]);
        } catch (QueryException) {
            throw new DeskException('That company is already there.');
        }

        return $this->companyParty($company);
    }

    public function createContact(string $companyId, string $name, string $email): Party
    {
        $name = trim($name);
        $email = strtolower(trim($email));

        if ($name === '') {
            throw new DeskException('A contact is required.');
        }

        if ($email === '' || ! str_contains($email, '@')) {
            throw new DeskException('A contact needs an email address.');
        }

        $company = $this->company($companyId);
        $existing = Contact::query()->where('email', $email)->orderBy('id')->get();

        if ($existing->count() > 1) {
            throw new DeskException('More than one contact matched ('.$existing->pluck('id')->implode(', ').'). Stop and choose one.');
        }

        if ($existing->count() === 1) {
            throw new DeskException('That contact is already there.');
        }

        try {
            $contact = $company->contacts()->create([
                'name' => $name,
                'email' => $email,
            ]);
        } catch (QueryException) {
            throw new DeskException('That contact is already there.');
        }

        return $this->contactParty($contact);
    }

    /**
     * @return list<array{id: string, name: string, contacts: list<array{id: string, name: string, email: string}>}>
     */
    public function directory(): array
    {
        $directory = [];

        foreach (Company::query()->with('contacts')->orderBy('name')->get() as $company) {
            $contacts = [];

            foreach ($company->contacts->sortBy('name') as $contact) {
                if ($contact->email === null || $contact->email === '') {
                    continue;
                }

                $contacts[] = [
                    'id' => (string) $contact->id,
                    'name' => $contact->name,
                    'email' => $contact->email,
                ];
            }

            $directory[] = [
                'id' => (string) $company->id,
                'name' => $company->name,
                'contacts' => array_values($contacts),
            ];
        }

        return $directory;
    }

    public function company(string $id): Company
    {
        $company = Company::query()->find($id);

        if (! $company instanceof Company) {
            throw new DeskException('No company matched.');
        }

        return $company;
    }

    public function contact(string $id): Contact
    {
        $contact = Contact::query()->find($id);

        if (! $contact instanceof Contact) {
            throw new DeskException('No contact matched.');
        }

        return $contact;
    }

    private function companyParty(Company $company): Party
    {
        return new Party((string) $company->id, $company->name);
    }

    private function contactParty(Contact $contact): Party
    {
        return new Party((string) $contact->id, $contact->name, (string) $contact->email);
    }
}
