<?php

namespace App\Desk\Notes;

use App\Clock\ClockNote;
use App\Clock\ParsedInbound;
use App\Clock\ParsedSent;
use App\Clock\ReceiptSource;
use App\Clock\ReplyClock;
use App\Desk\DeskException;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Email;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;

final class DatabaseNoteStore implements NoteStore
{
    public function assertPortal(): void
    {
        // The memory store is the showable copy. It has no HubSpot portal.
    }

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
        $matched = Company::query()->orderBy('id')->get()->filter(function (Company $company) use ($tokens): bool {
            $haystack = mb_strtolower($company->name);

            foreach ($tokens as $token) {
                if (! str_contains($haystack, mb_strtolower($token))) {
                    return false;
                }
            }

            return $tokens !== [];
        })->values();

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

    public function writeNote(string $body, string $companyId, string $contactId, ?NoteFile $file = null): string
    {
        $company = $this->company($companyId);
        $contact = $this->contact($contactId);
        $email = new Email([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            ...$this->fields($body),
        ]);

        try {
            $email->save();
        } catch (QueryException $exception) {
            if ($this->sameMinute($exception)) {
                throw new DeskException('That reply was already recorded at that minute.');
            }

            throw $exception;
        }

        if ($file !== null) {
            $this->keepFile((string) $email->id, $file);
            Attachment::query()->create([
                'email_id' => $email->id,
                'name' => $file->name,
                'path' => 'note-files/'.$email->id.'/'.$file->name,
            ]);
        }

        return (string) $email->id;
    }

    public function replaceNote(string $id, string $body): void
    {
        $email = Email::query()->find($id);

        if (! $email instanceof Email) {
            throw new DeskException("No note {$id}.");
        }

        $email->fill($this->fields($body));

        try {
            $email->save();
        } catch (QueryException $exception) {
            if ($this->sameMinute($exception)) {
                throw new DeskException('That reply was already recorded at that minute.');
            }

            throw $exception;
        }
    }

    public function attachmentFor(string $noteId): ?NoteAttachment
    {
        $attachment = Attachment::query()->where('email_id', $noteId)->orderBy('id')->first();

        if (! $attachment instanceof Attachment) {
            return null;
        }

        $name = basename($attachment->name);

        if ($name === '' || $name === '.' || $name === '..') {
            return null;
        }

        if (! Storage::disk('local')->exists($attachment->path)) {
            return null;
        }

        return new NoteAttachment($name, $attachment->path);
    }

    public function notes(): array
    {
        return Email::query()
            ->with(['company', 'contact', 'attachments'])
            ->orderBy('id')
            ->get()
            ->map(fn (Email $email): StoredNote => $this->stored($email))
            ->all();
    }

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

    /**
     * @return array{direction: string, subject: ?string, summary: ?string, body: string, at: string, receipt_source: ?string, closes_id: ?string}
     */
    private function fields(string $body): array
    {
        $parsed = ClockNote::parse($body);

        if ($parsed instanceof ParsedInbound) {
            $subject = $this->line($body, 'Subject');
            $summary = $this->line($body, 'Summary');

            return [
                'direction' => 'inbound',
                'subject' => $subject !== '' ? $subject : null,
                'summary' => $summary !== '' ? $summary : null,
                'body' => ClockNote::correspondence($body)['client'],
                'at' => $parsed->receipt->format('Y-m-d H:i:00'),
                'receipt_source' => $parsed->receiptSource->value,
                'closes_id' => null,
            ];
        }

        if ($parsed instanceof ParsedSent) {
            return [
                'direction' => 'outbound',
                'subject' => $parsed->subject,
                'summary' => null,
                'body' => ClockNote::correspondence($body)['reply'],
                'at' => $parsed->sent->format('Y-m-d H:i:00'),
                'receipt_source' => null,
                'closes_id' => $parsed->closes,
            ];
        }

        throw new DeskException('That note is not a clock note.');
    }

    private function stored(Email $email): StoredNote
    {
        $at = new DateTimeImmutable($email->at->format('Y-m-d H:i'), new DateTimeZone(ReplyClock::zoneName()));
        $attachment = $email->attachments->sortBy('id')->first();

        if ($email->direction === 'outbound') {
            $body = ClockNote::sent(
                (string) $email->closes_id,
                $at,
                (string) $email->subject,
                $email->body,
            );
        } else {
            $source = ReceiptSource::tryFrom((string) $email->receipt_source) ?? ReceiptSource::Logged;
            $body = ClockNote::withMail(
                ClockNote::inbound($at, $source, (string) $email->summary, (string) $email->subject),
                $email->body,
            );
        }

        return new StoredNote(
            (string) $email->id,
            $body,
            (string) $email->company_id,
            (string) $email->company?->name,
            (string) $email->contact_id,
            (string) $email->contact?->name,
            (string) $email->contact?->email,
            $attachment instanceof Attachment ? $attachment->name : null,
            $attachment instanceof Attachment ? (string) $attachment->id : null,
        );
    }

    private function line(string $body, string $label): string
    {
        foreach (preg_split("/\r\n|\n|\r/", $body) ?: [] as $line) {
            if (trim($line) === '') {
                break;
            }

            if (preg_match('/^'.preg_quote($label, '/').': (.+)$/', $line, $matches) === 1) {
                return $matches[1];
            }
        }

        return '';
    }

    private function company(string $id): Company
    {
        $company = Company::query()->find($id);

        if (! $company instanceof Company) {
            throw new DeskException('No company matched.');
        }

        return $company;
    }

    private function contact(string $id): Contact
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

    private function keepFile(string $id, NoteFile $file): void
    {
        if (! is_file($file->path)) {
            throw new DeskException('That attachment could not be read.');
        }

        $stored = Storage::disk('local')->put('note-files/'.$id.'/'.$file->name, fopen($file->path, 'r'));

        if ($stored === false) {
            throw new DeskException('That attachment could not be stored.');
        }
    }

    private function sameMinute(QueryException $exception): bool
    {
        return str_contains(strtolower($exception->getMessage()), 'unique')
            && str_contains($exception->getMessage(), 'closes_id');
    }
}
