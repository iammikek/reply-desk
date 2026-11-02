<?php

namespace App\Desk\Notes;

use Closure;
use Illuminate\Support\Facades\Cache;

final class HubSpotDirectory
{
    private const int CACHE_SECONDS = 3600;

    public function __construct(
        private HubSpotClient $client,
        private HubSpotCrmLookup $crm,
    ) {}

    /**
     * @return list<array{id: string, name: string, contacts: list<array{id: string, name: string, email: string}>}>
     */
    public function all(Closure $assertPortal): array
    {
        $cached = $this->cached();

        if ($cached !== null) {
            return $cached;
        }

        $assertPortal();

        $companies = $this->companies();
        $links = $this->crm->associated('companies', 'contacts', array_column($companies, 'id'));
        $contactIds = [];

        foreach ($links as $ids) {
            foreach ($ids as $id) {
                $contactIds[$id] = $id;
            }
        }

        $people = [];

        foreach (array_chunk(array_values($contactIds), 100) as $chunk) {
            $people += $this->crm->contacts($chunk);
        }

        $directory = [];

        foreach ($companies as $company) {
            $contacts = [];

            foreach ($links[$company['id']] ?? [] as $contactId) {
                $person = $people[$contactId] ?? null;

                if ($person === null || $person['email'] === '') {
                    continue;
                }

                $contacts[] = [
                    'id' => $contactId,
                    'name' => $person['name'],
                    'email' => $person['email'],
                ];
            }

            usort($contacts, fn (array $left, array $right): int => strcasecmp($left['name'].$left['email'], $right['name'].$right['email']));

            $directory[] = [
                'id' => $company['id'],
                'name' => $company['name'],
                'contacts' => $contacts,
            ];
        }

        usort($directory, fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));
        Cache::put($this->key(), $directory, self::CACHE_SECONDS);

        return $directory;
    }

    /**
     * @return list<array{id: string, name: string, contacts: list<array{id: string, name: string, email: string}>}>|null
     */
    public function cached(): ?array
    {
        $cached = Cache::get($this->key());

        if (! is_array($cached)) {
            if ($cached !== null) {
                $this->forget();
            }

            return null;
        }

        foreach ($cached as $company) {
            if (! is_array($company) || ! is_string($company['id'] ?? null) || ! is_string($company['name'] ?? null) || ! is_array($company['contacts'] ?? null)) {
                $this->forget();

                return null;
            }

            foreach ($company['contacts'] as $contact) {
                if (! is_array($contact) || ! is_string($contact['id'] ?? null) || ! is_string($contact['name'] ?? null) || ! is_string($contact['email'] ?? null)) {
                    $this->forget();

                    return null;
                }
            }
        }

        return $cached;
    }

    public function forget(): void
    {
        Cache::forget($this->key());
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function companies(): array
    {
        $companies = [];
        $after = null;

        for ($page = 0; $page < 10; $page++) {
            $query = ['limit' => 100, 'properties' => 'name'];

            if ($after !== null) {
                $query['after'] = $after;
            }

            $found = $this->client->send('GET', '/crm/objects/2026-03/companies', null, $query);

            foreach ($found['results'] ?? [] as $row) {
                $id = (string) ($row['id'] ?? '');
                $name = trim((string) ($row['properties']['name'] ?? ''));

                if ($id !== '' && $name !== '') {
                    $companies[$id] = ['id' => $id, 'name' => $name];
                }
            }

            $after = $found['paging']['next']['after'] ?? null;

            if (! is_string($after) || $after === '') {
                break;
            }
        }

        return array_values($companies);
    }

    private function key(): string
    {
        return 'reply-desk.directory.'.(config('reply-desk.portal_id'));
    }
}
