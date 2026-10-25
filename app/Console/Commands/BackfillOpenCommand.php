<?php

namespace App\Console\Commands;

use App\Clock\ClockNote;
use App\Clock\ParsedInbound;
use App\Clock\ParsedSent;
use App\Clock\ReceiptSource;
use App\Desk\Actions\LogInbound;
use App\Desk\Actions\MarkReplySent;
use App\Desk\Actions\SaveDraft;
use App\Desk\Backfill\FiledEmail;
use App\Desk\Backfill\OpenTable;
use App\Desk\Backfill\SentTableRow;
use App\Desk\DeskException;
use App\Desk\Notes\NoteStore;
use App\Desk\Notes\StoredNote;
use App\Models\Draft;
use DateTimeImmutable;

class BackfillOpenCommand extends ReplyDeskCommand
{
    protected $signature = 'reply:backfill {path} {--write : Write the notes when the store is HubSpot and a portal id is set}';

    protected $description = 'Print open and sent rows from the communications file, and write them only when the store is HubSpot and a portal id is set';

    public function handle(OpenTable $openTable, LogInbound $logInbound, MarkReplySent $markReplySent, SaveDraft $saveDraft, NoteStore $notes): int
    {
        try {
            $path = (string) $this->argument('path');
            $markdown = file_get_contents($path);

            if ($markdown === false) {
                throw new DeskException('That Open table could not be read.');
            }

            $rows = $openTable->rows($markdown);

            if (! $this->option('write')) {
                foreach ($openTable->preview($markdown, dirname($path)) as $line) {
                    $this->line($line);
                }

                return 0;
            }

            if (config('reply-desk.store') !== 'hubspot' || (string) config('reply-desk.portal_id') === '') {
                throw new DeskException('Backfill writes only when the store is HubSpot and a portal id is set.');
            }

            $notes->assertPortal();
            $existing = $notes->notes();

            foreach ($rows as $row) {
                try {
                    $company = $notes->findCompany($row->company);
                } catch (DeskException $exception) {
                    if ($this->unmatched($exception)) {
                        $this->line('Skipped: '.$exception->getMessage());

                        continue;
                    }

                    throw $exception;
                }

                $client = $this->mail($path, $row->clientEmailPath, $row->company, 'client email');
                $reply = $this->mail($path, $row->replyPath, $row->company, 'reply');

                if ($this->alreadyOpen($existing, $company->id, $row->receipt)) {
                    $this->fileOnto($existing, $company->id, $row->receipt, $client, $reply, $notes);
                    $this->saveDraft($this->inboundId($existing, $company->id, $row->receipt), $reply, $saveDraft, $company->name);
                    $this->line("Already open for {$company->name}");

                    continue;
                }

                $note = $this->inboundNote($row->receipt, $row->receiptSource, $row->summary, $client, $reply);
                $id = $logInbound(
                    $row->company,
                    $row->email,
                    $row->receipt,
                    $row->receiptSource,
                    $row->summary,
                    $client?->body ?? '',
                    $reply?->body ?? '',
                    subject: $client?->subject ?? '',
                );
                $existing[] = new StoredNote($id, $note, $company->id, $company->name, '', '', $row->email);
                $this->line("Wrote {$id} for {$row->company}");
                $this->saveDraft($id, $reply, $saveDraft, $company->name);
            }

            $sent = $openTable->sent($markdown);

            foreach ($sent['skipped'] as $message) {
                $this->line('Skipped: '.$message);
            }

            foreach ($sent['rows'] as $row) {
                $this->writeSent($row, $path, $logInbound, $markReplySent, $notes, $existing);
            }

            return 0;
        } catch (DeskException $exception) {
            return $this->failure($exception);
        }
    }

    /**
     * @param  list<StoredNote>  $existing
     */
    private function writeSent(SentTableRow $row, string $markdownPath, LogInbound $logInbound, MarkReplySent $markReplySent, NoteStore $notes, array &$existing): void
    {
        try {
            $company = $notes->findCompany($row->company);
            $client = $this->mail($markdownPath, $row->clientEmailPath, $row->company, 'client email');
            $reply = $this->mail($markdownPath, $row->replyPath, $row->company, 'reply');
            $subject = $reply instanceof FiledEmail && $reply->subject !== '' ? $reply->subject : $row->subject;
            $replyBody = $reply?->body ?? '';
            $inboundId = $this->inboundId($existing, $company->id, $row->receipt);

            if ($inboundId === null) {
                $note = $this->inboundNote($row->receipt, $row->receiptSource, $row->summary, $client, null);
                $inboundId = $logInbound(
                    $row->company,
                    $row->email,
                    $row->receipt,
                    $row->receiptSource,
                    $row->summary,
                    $client?->body ?? '',
                    subject: $client?->subject ?? '',
                );
                $existing[] = new StoredNote($inboundId, $note, $company->id, $company->name, '', '', $row->email);
                $this->line("Wrote {$inboundId} for {$company->name}");
            } else {
                $this->fileOnto($existing, $company->id, $row->receipt, $client, null, $notes);
            }

            if ($this->alreadySent($existing, $inboundId, $row->sentAt)) {
                $this->refreshSent($existing, $inboundId, $row->sentAt, $reply instanceof FiledEmail ? $reply->subject : '', $replyBody, $notes);
                $this->line("Already sent for {$company->name}");

                return;
            }

            $replyText = $replyBody;

            if ($this->alreadyClosed($existing, $inboundId)) {
                $contact = $notes->findContact($row->email);
                $sentId = $notes->writeNote(
                    ClockNote::sent($inboundId, $row->sentAt, $subject, $replyText),
                    $company->id,
                    $contact->id,
                );
            } else {
                $sentId = $markReplySent($inboundId, $row->sentAt, $subject, $replyText);
            }

            $existing[] = new StoredNote($sentId, ClockNote::sent($inboundId, $row->sentAt, $subject, $replyText), $company->id, $company->name, '', '', $row->email);
            $this->line("Wrote {$sentId} closing {$inboundId} for {$company->name}");
        } catch (DeskException $exception) {
            if ($this->unmatched($exception)) {
                $this->line('Skipped: '.$exception->getMessage());

                return;
            }

            throw $exception;
        }
    }

    /**
     * @param  list<StoredNote>  $existing
     */
    private function fileOnto(array &$existing, string $companyId, DateTimeImmutable $receipt, ?FiledEmail $client, ?FiledEmail $reply, NoteStore $notes): void
    {
        foreach ($existing as $index => $note) {
            $parsed = ClockNote::parse($note->body);

            if (! $parsed instanceof ParsedInbound || $note->companyId !== $companyId) {
                continue;
            }

            if ($parsed->receipt->format('Y-m-d H:i') !== $receipt->format('Y-m-d H:i')) {
                continue;
            }

            $next = ClockNote::applyMail($note->body, $client?->body ?? '', $reply?->body ?? '', $client?->subject ?? '');

            if ($next === $note->body) {
                return;
            }

            $notes->replaceNote($note->id, $next);
            $existing[$index] = new StoredNote($note->id, $next, $note->companyId, $note->companyName, $note->contactId, $note->contactName, $note->contactEmail, $note->attachmentName, $note->attachmentId);
            $this->line("Filed the email on {$note->id} for {$note->companyName}");

            return;
        }
    }

    /**
     * @param  list<StoredNote>  $existing
     */
    private function refreshSent(array &$existing, string $inboundId, DateTimeImmutable $sentAt, string $subject, string $reply, NoteStore $notes): void
    {
        if ($subject === '' && $reply === '') {
            return;
        }

        foreach ($existing as $index => $note) {
            $parsed = ClockNote::parse($note->body);

            if (! $parsed instanceof ParsedSent || $parsed->closes !== $inboundId) {
                continue;
            }

            if ($parsed->sent->format('Y-m-d H:i') !== $sentAt->format('Y-m-d H:i')) {
                continue;
            }

            $next = ClockNote::applyMail($note->body, reply: $reply, subject: $subject);

            if ($next === $note->body) {
                return;
            }

            $notes->replaceNote($note->id, $next);
            $existing[$index] = new StoredNote($note->id, $next, $note->companyId, $note->companyName, $note->contactId, $note->contactName, $note->contactEmail, $note->attachmentName, $note->attachmentId);
            $this->line("Filed the reply on {$note->id}");

            return;
        }
    }

    private function inboundNote(DateTimeImmutable $receipt, ReceiptSource $source, string $summary, ?FiledEmail $client, ?FiledEmail $reply): string
    {
        return ClockNote::withMail(
            ClockNote::inbound($receipt, $source, $summary, $client?->subject ?? ''),
            $client?->body ?? '',
            $reply?->body ?? '',
        );
    }

    private function saveDraft(?string $inboundId, ?FiledEmail $reply, SaveDraft $saveDraft, string $company): void
    {
        if ($inboundId === null || ! $reply instanceof FiledEmail || $reply->body === '') {
            return;
        }

        if (Draft::query()->where(Draft::forInbound($inboundId))->exists()) {
            return;
        }

        $saveDraft($inboundId, $reply->subject !== '' ? $reply->subject : 'Reply', $reply->body);
        $this->line("Draft saved for {$company}");
    }

    private function mail(string $markdownPath, ?string $relative, string $company, string $role): ?FiledEmail
    {
        if ($relative === null || $relative === '') {
            return null;
        }

        $mail = FiledEmail::read(dirname($markdownPath), $relative);

        if (! $mail instanceof FiledEmail) {
            $this->line('Skipped: '.$company.' '.$role.' file could not be read.');

            return null;
        }

        return $mail;
    }

    private function unmatched(DeskException $exception): bool
    {
        return str_starts_with($exception->getMessage(), 'No company matched')
            || str_starts_with($exception->getMessage(), 'No contact matched');
    }

    /**
     * @param  list<StoredNote>  $notes
     */
    private function inboundId(array $notes, string $companyId, DateTimeImmutable $receipt): ?string
    {
        foreach ($notes as $note) {
            $parsed = ClockNote::parse($note->body);

            if ($parsed instanceof ParsedInbound
                && $note->companyId === $companyId
                && $parsed->receipt->format('Y-m-d H:i') === $receipt->format('Y-m-d H:i')) {
                return $note->id;
            }
        }

        return null;
    }

    /**
     * @param  list<StoredNote>  $notes
     */
    private function alreadyClosed(array $notes, string $inboundId): bool
    {
        foreach ($notes as $note) {
            $parsed = ClockNote::parse($note->body);

            if ($parsed instanceof ParsedSent && $parsed->closes === $inboundId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<StoredNote>  $notes
     */
    private function alreadySent(array $notes, string $inboundId, DateTimeImmutable $sentAt): bool
    {
        foreach ($notes as $note) {
            $parsed = ClockNote::parse($note->body);

            if ($parsed instanceof ParsedSent
                && $parsed->closes === $inboundId
                && $parsed->sent->format('Y-m-d H:i') === $sentAt->format('Y-m-d H:i')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<StoredNote>  $notes
     */
    private function alreadyOpen(array $notes, string $companyId, DateTimeImmutable $receipt): bool
    {
        $closed = [];

        foreach ($notes as $note) {
            $parsed = ClockNote::parse($note->body);

            if ($parsed instanceof ParsedSent) {
                $closed[$parsed->closes] = true;
            }
        }

        foreach ($notes as $note) {
            $parsed = ClockNote::parse($note->body);

            if (! $parsed instanceof ParsedInbound || isset($closed[$note->id]) || $note->companyId !== $companyId) {
                continue;
            }

            if ($parsed->receipt->format('Y-m-d H:i') === $receipt->format('Y-m-d H:i')) {
                return true;
            }
        }

        return false;
    }
}
