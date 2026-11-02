<?php

namespace App\Desk\Notes;

use App\Desk\DeskException;

final class HubSpotCrmLookup
{
    public function __construct(private HubSpotClient $client) {}

    /**
     * @param  list<string>  $values
     * @return list<array<string, mixed>>
     */
    public function companyResults(string $operator, array $values): array
    {
        $found = $this->client->send('POST', '/crm/objects/2026-03/companies/search', [
            'filterGroups' => [[
                'filters' => array_map(fn (string $value): array => [
                    'propertyName' => 'name',
                    'operator' => $operator,
                    'value' => $value,
                ], $values),
            ]],
            'properties' => ['name'],
            'limit' => 10,
        ]);

        return $found['results'] ?? [];
    }

    /**
     * @param  list<array{id: string, properties: array{hs_note_body: string, hs_attachment_ids: string}}>  $notes
     * @return list<array<string, mixed>>
     */
    public function withAssociations(array $notes): array
    {
        if ($notes === []) {
            return [];
        }

        $ids = array_column($notes, 'id');
        $companies = $this->associated('notes', 'companies', $ids);
        $contacts = $this->associated('notes', 'contacts', $ids);

        foreach ($notes as $index => $note) {
            $notes[$index]['associations'] = [
                'companies' => ['results' => $this->associationResults($companies[$note['id']] ?? [])],
                'contacts' => ['results' => $this->associationResults($contacts[$note['id']] ?? [])],
            ];
        }

        return $notes;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, list<string>>
     */
    public function associated(string $fromObject, string $object, array $ids): array
    {
        $linked = [];

        foreach (array_chunk($ids, 100) as $chunk) {
            $found = $this->client->send('POST', "/crm/associations/2026-03/{$fromObject}/{$object}/batch/read", [
                'inputs' => array_map(fn (string $id): array => ['id' => $id], $chunk),
            ]);

            foreach ($found['results'] ?? [] as $row) {
                $from = (string) ($row['from']['id'] ?? '');

                if ($from === '') {
                    continue;
                }

                foreach ($row['to'] ?? [] as $to) {
                    $toId = (string) ($to['toObjectId'] ?? '');

                    if ($toId !== '') {
                        $linked[$from][$toId] = $toId;
                    }
                }
            }
        }

        return array_map(
            array_values(...),
            $linked,
        );
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    public function names(string $object, array $ids, string $property): array
    {
        if ($ids === []) {
            return [];
        }

        $found = $this->client->send('POST', "/crm/objects/2026-03/{$object}/batch/read", [
            'properties' => [$property],
            'inputs' => array_map(fn (string $id): array => ['id' => $id], $ids),
        ]);

        $names = [];

        foreach ($found['results'] ?? [] as $row) {
            $names[(string) $row['id']] = (string) ($row['properties'][$property] ?? '');
        }

        return $names;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array{name: string, email: string}>
     */
    public function contacts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $found = $this->client->send('POST', '/crm/objects/2026-03/contacts/batch/read', [
            'properties' => ['email', 'firstname', 'lastname'],
            'inputs' => array_map(fn (string $id): array => ['id' => $id], $ids),
        ]);

        $contacts = [];

        foreach ($found['results'] ?? [] as $row) {
            $properties = $row['properties'] ?? [];
            $contacts[(string) $row['id']] = [
                'name' => trim((($properties['firstname'] ?? '')).' '.(($properties['lastname'] ?? ''))),
                'email' => (string) ($properties['email'] ?? ''),
            ];
        }

        return $contacts;
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    public function onlyId(array $results): ?string
    {
        $ids = [];

        foreach ($results as $result) {
            $id = (string) ($result['id'] ?? '');

            if ($id !== '') {
                $ids[$id] = $id;
            }
        }

        if (count($ids) !== 1) {
            return null;
        }

        return array_values($ids)[0];
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    public function one(array $results, string $label): array
    {
        if (count($results) === 0) {
            throw new DeskException('No '.$label.' matched.');
        }

        if (count($results) > 1) {
            $ids = implode(', ', array_map(fn (array $result): string => (string) $result['id'], $results));

            throw new DeskException("More than one {$label} matched ({$ids}). Stop and choose one.");
        }

        return $results[0];
    }

    /**
     * @param  list<string>  $ids
     * @return list<array{id: string}>
     */
    private function associationResults(array $ids): array
    {
        return array_map(fn (string $id): array => ['id' => $id], $ids);
    }
}
