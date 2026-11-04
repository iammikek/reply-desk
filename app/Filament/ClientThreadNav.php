<?php

declare(strict_types=1);

namespace App\Filament;

use App\Desk\DeskException;
use App\Desk\Queue\ListClientCorrespondence;
use App\Filament\Pages\Client;
use App\Filament\Resources\Contacts\Pages\ViewContact;
use App\Filament\Resources\Inbounds\Pages\ViewInbound;

final class ClientThreadNav
{
    /**
     * @return list<array{id: string, label: string, url: string}>
     */
    public static function items(): array
    {
        [$companyId, $contactId, $page, $recordId] = self::currentParty();

        if ($companyId === null || $contactId === null || $page === null) {
            return [];
        }

        try {
            $found = app(ListClientCorrespondence::class)($companyId, $contactId);
        } catch (DeskException) {
            // Sidebar can render without a live note store (for example HubSpot token missing).
            return [];
        }

        if ($found === null) {
            return [];
        }

        $pageUrl = match ($page) {
            'contact' => ViewContact::getUrl(['record' => $contactId]),
            'inbound' => ViewInbound::getUrl(['record' => (string) $recordId]),
            default => Client::urlFor($companyId, $contactId),
        };

        $items = [];

        foreach ([...$found->replies(), ...$found->outreach()] as $thread) {
            $label = trim((string) ($thread['subject'] ?? ''));

            if ($label === '') {
                $label = trim((string) ($thread['summary'] ?? ''));
            }

            if ($label === '') {
                $label = 'Thread';
            }

            $id = (string) $thread['id'];

            $items[] = [
                'id' => $id,
                'label' => $label,
                'url' => $pageUrl.'#thread-'.$id,
            ];
        }

        return $items;
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: 'client'|'contact'|'inbound'|null, 3: string|null}
     */
    private static function currentParty(): array
    {
        foreach (self::candidatePaths() as $path) {
            $party = self::partyFromPath($path);

            if ($party[0] !== null && $party[1] !== null && $party[2] !== null) {
                return $party;
            }
        }

        return [null, null, null, null];
    }

    /**
     * @return list<string>
     */
    private static function candidatePaths(): array
    {
        $paths = ['/'.ltrim(request()->path(), '/')];

        $referer = request()->headers->get('Referer');

        if (is_string($referer) && $referer !== '') {
            $refererPath = parse_url($referer, PHP_URL_PATH);

            if (is_string($refererPath) && $refererPath !== '') {
                $paths[] = $refererPath;
            }
        }

        foreach (request()->input('components', []) as $component) {
            if (! is_array($component)) {
                continue;
            }

            $snapshot = $component['snapshot'] ?? null;

            if (! is_string($snapshot)) {
                continue;
            }

            $decoded = json_decode($snapshot, true);
            $path = is_array($decoded) ? ($decoded['memo']['path'] ?? null) : null;

            if (is_string($path) && $path !== '') {
                $paths[] = '/'.ltrim($path, '/');
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: 'client'|'contact'|'inbound'|null, 3: string|null}
     */
    public static function partyFromPath(string $path): array
    {
        $path = '/'.ltrim($path, '/');

        if (preg_match('#^/clients/([^/]+)/([^/]+)/?$#', $path, $matches) === 1) {
            return [$matches[1], $matches[2], 'client', null];
        }

        if (preg_match('#^/contacts/([^/]+)/?$#', $path, $matches) === 1) {
            $contactId = $matches[1];
            $contact = DirectoryRecords::contact($contactId);

            if ($contact === null) {
                return [null, null, 'contact', $contactId];
            }

            return [(string) $contact['company_id'], $contactId, 'contact', $contactId];
        }

        if (preg_match('#^/inbounds/([^/]+)/?$#', $path, $matches) === 1) {
            $inboundId = $matches[1];
            $inbound = InboundRecords::find($inboundId);

            if ($inbound === null) {
                return [null, null, 'inbound', $inboundId];
            }

            return [$inbound['company_id'], $inbound['contact_id'], 'inbound', $inboundId];
        }

        return [null, null, null, null];
    }
}
