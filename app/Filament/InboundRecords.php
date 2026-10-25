<?php

namespace App\Filament;

use App\Clock\ClockNote;
use App\Clock\ParsedInbound;
use App\Clock\ParsedSent;
use App\Clock\ReplyClock;
use App\Desk\Notes\NoteStore;
use DateTimeZone;
use Filament\Support\ArrayRecord;

final class InboundRecords
{
    /**
     * @return array{id: string, summary: string, subject: string, body: string, at: string, company_id: string, contact_id: string, company: string, contact: string, email: string}|null
     */
    public static function find(string $id): ?array
    {
        foreach (app(NoteStore::class)->notes() as $note) {
            if ($note->id !== $id) {
                continue;
            }

            $parsed = ClockNote::parse($note->body);

            if (! $parsed instanceof ParsedInbound) {
                return null;
            }

            return [
                'id' => $note->id,
                'summary' => $parsed->summary,
                'subject' => self::line($note->body, 'Subject'),
                'body' => ClockNote::correspondence($note->body)['client'],
                'at' => $parsed->receipt->setTimezone(new DateTimeZone(ReplyClock::ZONE))->format('Y-m-d H:i:s'),
                'company_id' => $note->companyId,
                'contact_id' => $note->contactId,
                'company' => $note->companyName,
                'contact' => $note->contactName,
                'email' => $note->contactEmail,
            ];
        }

        return null;
    }

    /**
     * @return list<array<string, string>>
     */
    public static function replies(string $inboundId): array
    {
        $replies = [];

        foreach (app(NoteStore::class)->notes() as $note) {
            $parsed = ClockNote::parse($note->body);

            if (! $parsed instanceof ParsedSent || $parsed->closes !== $inboundId) {
                continue;
            }

            $replies[] = [
                ArrayRecord::getKeyName() => $note->id,
                'sent' => $parsed->sent->setTimezone(new DateTimeZone(ReplyClock::ZONE))->format('Y-m-d H:i'),
                'subject' => $parsed->subject,
                'body' => ClockNote::correspondence($note->body)['reply'],
            ];
        }

        usort($replies, fn (array $left, array $right): int => $left['sent'] <=> $right['sent']);

        return $replies;
    }

    private static function line(string $body, string $label): string
    {
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            if (str_starts_with($line, $label.': ')) {
                return trim(substr($line, strlen($label) + 2));
            }
        }

        return '';
    }
}
