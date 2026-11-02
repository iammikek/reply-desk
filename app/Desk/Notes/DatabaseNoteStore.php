<?php

namespace App\Desk\Notes;

use App\Clock\ClockNote;
use App\Clock\ParsedClosed;
use App\Clock\ParsedInbound;
use App\Clock\ParsedSent;
use App\Clock\ReceiptSource;
use App\Clock\ReplyClock;
use App\Desk\DeskException;
use App\Models\Attachment;
use App\Models\Email;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;

final class DatabaseNoteStore implements NoteStore
{
    private DatabaseParties $parties;

    public function __construct(?DatabaseParties $parties = null)
    {
        $this->parties = $parties ?? new DatabaseParties;
    }

    public function assertPortal(): void
    {
        // The memory store is the showable copy. It has no HubSpot portal.
    }

    public function findCompany(string $name): Party
    {
        return $this->parties->findCompany($name);
    }

    public function findContact(string $email): Party
    {
        return $this->parties->findContact($email);
    }

    public function createCompany(string $name): Party
    {
        return $this->parties->createCompany($name);
    }

    public function createContact(string $companyId, string $name, string $email): Party
    {
        return $this->parties->createContact($companyId, $name, $email);
    }

    public function writeNote(string $body, string $companyId, string $contactId, ?NoteFile $file = null): string
    {
        $company = $this->parties->company($companyId);
        $contact = $this->parties->contact($contactId);
        $email = new Email([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            ...$this->fields($body),
        ]);

        try {
            $email->save();
        } catch (QueryException $exception) {
            if ($this->sameMinute($exception)) {
                throw new DeskException('That reply was already recorded at that minute.', $exception->getCode(), $exception);
            }

            throw $exception;
        }

        if ($file instanceof NoteFile) {
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
                throw new DeskException('That reply was already recorded at that minute.', $exception->getCode(), $exception);
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

        if (in_array($name, ['', '.', '..'], true)) {
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
        return $this->parties->directory();
    }

    /**
     * @return array{direction: string, subject: ?string, summary: ?string, body: string, at: string, receipt_source: ?string, closes_id: ?string}
     */
    private function fields(string $body): array
    {
        $parsed = ClockNote::parse($body);

        if ($parsed instanceof ParsedInbound) {
            $subject = ClockNote::headerField($body, 'Subject');
            $summary = ClockNote::headerField($body, 'Summary');

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

        if ($parsed instanceof ParsedClosed) {
            return [
                'direction' => 'closed',
                'subject' => null,
                'summary' => null,
                'body' => '',
                'at' => $parsed->closed->format('Y-m-d H:i:00'),
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
        } elseif ($email->direction === 'closed') {
            $body = ClockNote::closed((string) $email->closes_id, $at);
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
