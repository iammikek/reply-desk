<?php

namespace App\Filament;

use App\Clock\ClockNote;
use App\Clock\ParsedInbound;
use App\Clock\ParsedSent;
use App\Clock\ReplyClock;
use App\Desk\Notes\NoteStore;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Email;
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
                'subject' => ClockNote::subjectOf($note->body),
                'body' => ClockNote::correspondence($note->body)['client'],
                'at' => $parsed->receipt->setTimezone(new DateTimeZone(ReplyClock::zoneName()))->format('Y-m-d H:i:s'),
                'company_id' => $note->companyId,
                'contact_id' => $note->contactId,
                'company' => $note->companyName,
                'contact' => $note->contactName,
                'email' => $note->contactEmail,
            ];
        }

        return null;
    }

    public static function model(string $id): ?Email
    {
        $row = self::find($id);

        if ($row === null) {
            return null;
        }

        if (config('reply-desk.store') !== 'hubspot') {
            $email = Email::query()->with(['company', 'contact'])->find($id);

            if ($email instanceof Email) {
                return $email;
            }
        }

        $email = new Email([
            'summary' => $row['summary'],
            'subject' => $row['subject'] !== '' ? $row['subject'] : null,
            'body' => $row['body'],
            'at' => $row['at'],
            'company_id' => $row['company_id'],
            'contact_id' => $row['contact_id'],
            'direction' => 'inbound',
        ]);
        $email->setAttribute('id', $row['id']);
        $email->setRelation('company', new Company(['name' => $row['company']]));
        $email->setRelation('contact', new Contact([
            'name' => $row['contact'],
            'email' => $row['email'],
            'company_id' => $row['company_id'],
        ]));

        return $email;
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
                'sent' => $parsed->sent->setTimezone(new DateTimeZone(ReplyClock::zoneName()))->format('Y-m-d H:i'),
                'subject' => $parsed->subject,
                'body' => ClockNote::correspondence($note->body)['reply'],
            ];
        }

        usort($replies, fn (array $left, array $right): int => $left['sent'] <=> $right['sent']);

        return $replies;
    }
}
