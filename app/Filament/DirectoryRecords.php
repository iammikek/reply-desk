<?php

namespace App\Filament;

use App\Desk\Notes\NoteStore;
use App\Models\Company;
use App\Models\Contact;

final class DirectoryRecords
{
    /**
     * @return list<array{id: string, name: string, contacts: int}>
     */
    public static function companies(): array
    {
        return array_map(fn (array $company): array => [
            'id' => $company['id'],
            'name' => $company['name'],
            'contacts' => count($company['contacts']),
        ], app(NoteStore::class)->directory());
    }

    /**
     * @return list<array{id: string, name: string, email: string, company: string, company_id: string}>
     */
    public static function contacts(): array
    {
        $rows = [];

        foreach (app(NoteStore::class)->directory() as $company) {
            foreach ($company['contacts'] as $contact) {
                $rows[] = [
                    'id' => $contact['id'],
                    'name' => $contact['name'],
                    'email' => $contact['email'],
                    'company' => $company['name'],
                    'company_id' => $company['id'],
                ];
            }
        }

        return $rows;
    }

    /**
     * @return array{id: string, name: string, contacts: list<array{id: string, name: string, email: string}>}|null
     */
    public static function company(string $id): ?array
    {
        foreach (app(NoteStore::class)->directory() as $company) {
            if ($company['id'] === $id) {
                return $company;
            }
        }

        return null;
    }

    /**
     * @return array{id: string, name: string, email: string, company: string, company_id: string}|null
     */
    public static function contact(string $id): ?array
    {
        foreach (self::contacts() as $contact) {
            if ($contact['id'] === $id) {
                return $contact;
            }
        }

        return null;
    }

    public static function companyModel(string $id): ?Company
    {
        $row = self::company($id);

        if ($row === null) {
            return null;
        }

        if (config('reply-desk.store') !== 'hubspot') {
            $company = Company::query()->find($id);

            if ($company instanceof Company) {
                return $company;
            }
        }

        $company = new Company(['name' => $row['name']]);
        $company->setAttribute('id', $row['id']);

        return $company;
    }

    public static function contactModel(string $id): ?Contact
    {
        $row = self::contact($id);

        if ($row === null) {
            return null;
        }

        if (config('reply-desk.store') !== 'hubspot') {
            $contact = Contact::query()->find($id);

            if ($contact instanceof Contact) {
                $contact->load('company');

                return $contact;
            }
        }

        $contact = new Contact([
            'name' => $row['name'],
            'email' => $row['email'],
            'company_id' => $row['company_id'],
        ]);
        $contact->setAttribute('id', $row['id']);
        $contact->setRelation('company', new Company(['name' => $row['company']]));

        return $contact;
    }
}
