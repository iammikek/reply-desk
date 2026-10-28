<?php

namespace App\Desk\Actions;

use App\Clock\ReceiptSource;
use App\Clock\ReplyClock;
use App\Desk\InboundSummary;
use App\Desk\LogDeskActivity;
use App\Desk\Mailbox\ExtractLatestMessage;
use App\Desk\Mailbox\IncomingMessage;
use App\Desk\Mailbox\Mailbox;
use App\Desk\Notes\NoteStore;
use App\Models\MailboxState;
use App\Models\MailMessageId;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class PollMailbox
{
    public function __construct(
        private Mailbox $mailbox,
        private NoteStore $notes,
        private LogInbound $logInbound,
        private ExtractLatestMessage $extract,
        private LogDeskActivity $log,
    ) {}

    /**
     * @return array{kept: int, skipped: int, noop: bool, failed: bool}
     */
    public function __invoke(bool $force = false): array
    {
        if (! $this->mailbox->configured()) {
            return ['kept' => 0, 'skipped' => 0, 'noop' => true, 'failed' => false];
        }

        $state = MailboxState::current();

        if (! $force && ! $state->dueForScheduledPoll()) {
            return ['kept' => 0, 'skipped' => 0, 'noop' => true, 'failed' => false];
        }

        ($this->log)('poll.started', 'Mailbox poll started.');

        try {
            $fetch = $this->mailbox->fetchSince($state->inbox_cursor, $state->inbox_uidvalidity);
        } catch (Throwable $exception) {
            Log::warning('Mailbox poll failed.', [
                'error' => $exception->getMessage(),
            ]);

            // Keep last_polled_at unchanged so the schedule retries promptly.
            $state->forceFill([
                'last_poll_error' => 'Poll failed.',
            ])->save();

            ($this->log)('poll.failed', 'Mailbox poll failed.', context: [
                'error' => 'connection',
                'detail' => $exception->getMessage(),
            ]);

            return ['kept' => 0, 'skipped' => 0, 'noop' => false, 'failed' => true];
        }

        $uidValidityChanged = $fetch->uidValidity !== null
            && $state->inbox_uidvalidity !== null
            && $fetch->uidValidity !== $state->inbox_uidvalidity;

        if ($uidValidityChanged) {
            ($this->log)('poll.uidvalidity_reset', 'Inbox UIDVALIDITY changed; cursor reset.', context: [
                'previous' => $state->inbox_uidvalidity,
                'current' => $fetch->uidValidity,
            ]);
        }

        $kept = 0;
        $skipped = 0;
        $latestCursor = $uidValidityChanged ? null : $state->inbox_cursor;
        $own = strtolower(trim((string) config('reply-desk.mailbox.from_address')));

        foreach ($fetch->messages as $message) {
            $latestCursor = $message->cursor;
            $result = $this->ingest($message, $own);

            if ($result === 'kept') {
                $kept++;
            } else {
                $skipped++;
                ($this->log)(
                    'poll.skipped',
                    'Mailbox message skipped.',
                    context: ['reason' => $result, 'message_id' => $message->messageId],
                );
            }
        }

        $state->forceFill([
            'inbox_cursor' => $latestCursor,
            'inbox_uidvalidity' => $fetch->uidValidity ?? $state->inbox_uidvalidity,
            'last_polled_at' => now(),
            'last_poll_error' => null,
        ])->save();

        if ($kept > 0) {
            ($this->log)('poll.kept', "Logged {$kept} inbound message(s).", context: [
                'kept' => $kept,
                'skipped' => $skipped,
            ]);
        }

        return ['kept' => $kept, 'skipped' => $skipped, 'noop' => false, 'failed' => false];
    }

    private function ingest(IncomingMessage $message, string $ownFrom): string
    {
        if ($message->messageId !== '' && MailMessageId::known($message->messageId)) {
            return 'duplicate_message_id';
        }

        $from = strtolower(trim($message->fromEmail));

        if ($from === '' || ($ownFrom !== '' && $from === $ownFrom)) {
            return 'own_from';
        }

        if (! $message->date instanceof DateTimeImmutable) {
            return 'date_only';
        }

        $receipt = $this->receipt($message->date);

        if (! $receipt instanceof DateTimeImmutable) {
            return 'date_only';
        }

        $body = ($this->extract)($message->textBody, $message->htmlBody);

        if ($body === '') {
            return 'empty_after_extract';
        }

        $match = $this->matchContact($from);

        if ($match === null) {
            return 'unknown_from';
        }

        [$companyId, $contactId] = $match;

        $summary = InboundSummary::from($message->subject, $body);
        $inboundId = $this->logInbound->record(
            $companyId,
            $contactId,
            $receipt,
            ReceiptSource::MailClient,
            $summary,
            $body,
            subject: $message->subject,
            activityContext: [
                'message_id' => $message->messageId,
                'via' => 'poll',
                'reply_to_stored' => $this->referencesKnown($message),
            ],
        );

        if ($message->messageId !== '') {
            MailMessageId::remember($message->messageId, $inboundId, 'inbound');
        }

        return 'kept';
    }

    private function receipt(DateTimeImmutable $date): ?DateTimeImmutable
    {
        $zone = new DateTimeZone(ReplyClock::zoneName());
        $local = $date->setTimezone($zone);

        return DateTimeImmutable::createFromFormat('Y-m-d H:i', $local->format('Y-m-d H:i'), $zone) ?: null;
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}|null
     */
    private function matchContact(string $email): ?array
    {
        $matches = [];

        foreach ($this->notes->directory() as $company) {
            foreach ($company['contacts'] as $contact) {
                if (strtolower(trim((string) $contact['email'])) === $email) {
                    $matches[] = [
                        (string) $company['id'],
                        (string) $contact['id'],
                        (string) $company['name'],
                        (string) $contact['name'],
                    ];
                }
            }
        }

        if (count($matches) !== 1) {
            return null;
        }

        return $matches[0];
    }

    private function referencesKnown(IncomingMessage $message): bool
    {
        $ids = [];

        if ($message->inReplyTo !== '') {
            $ids[] = $message->inReplyTo;
        }

        foreach ($message->references as $reference) {
            $ids[] = $reference;
        }

        return array_any(array_unique($ids), fn (string $messageId): bool => MailMessageId::known($messageId));
    }
}
