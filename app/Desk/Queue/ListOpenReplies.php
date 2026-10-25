<?php

namespace App\Desk\Queue;

use App\Clock\ClockNote;
use App\Clock\ParsedInbound;
use App\Clock\ParsedSent;
use App\Clock\ReplyClock;
use App\Desk\Notes\NoteStore;
use App\Models\Draft;
use DateTimeImmutable;

final class ListOpenReplies
{
    public function __construct(
        private NoteStore $notes,
        private ReplyClock $clock,
    ) {}

    /**
     * @return list<OpenReply>
     */
    public function __invoke(?DateTimeImmutable $now = null, ?bool $overdue = null): array
    {
        $now ??= DateTimeImmutable::createFromInterface(now(ReplyClock::ZONE));
        $sent = [];
        $inbound = [];

        foreach ($this->notes->notes() as $note) {
            $parsed = ClockNote::parse($note->body);

            if ($parsed instanceof ParsedSent) {
                $sent[$parsed->closes][] = [$note, $parsed];
            }

            if ($parsed instanceof ParsedInbound) {
                $inbound[] = [$note, $parsed];
            }
        }

        $earlierByCompany = [];

        foreach ($inbound as [$note, $parsed]) {
            $replies = $sent[$note->id] ?? null;

            if ($replies === null) {
                continue;
            }

            usort($replies, fn (array $left, array $right): int => $left[1]->sent <=> $right[1]->sent);

            $earlierByCompany[$note->companyId][] = new EarlierThread(
                $parsed->receipt,
                $parsed->summary,
                ClockNote::correspondence($note->body)['client'],
                array_map(fn (array $reply): array => [
                    'sent' => $reply[1]->sent,
                    'subject' => $reply[1]->subject,
                    'body' => ClockNote::correspondence($reply[0]->body)['reply'],
                ], $replies),
            );
        }

        foreach ($earlierByCompany as $companyId => $threads) {
            usort($threads, fn (EarlierThread $left, EarlierThread $right): int => $right->receipt <=> $left->receipt);
            $earlierByCompany[$companyId] = $threads;
        }

        $open = [];
        $drafts = Draft::query()
            ->whereIn(Draft::inboundColumn(), array_map(fn (array $row): string => $row[0]->id, $inbound))
            ->get()
            ->keyBy(Draft::inboundColumn());

        foreach ($inbound as [$note, $parsed]) {
            if (isset($sent[$note->id])) {
                continue;
            }

            $schedule = $this->clock->schedule($parsed->receipt);
            $contact = $note->contactName !== '' ? $note->contactName : $note->contactEmail;
            $draft = $drafts->get($note->id);

            $open[] = new OpenReply(
                $note->id,
                trim($note->companyName.' / '.$contact, ' /'),
                $parsed->summary,
                $note->contactEmail,
                $note->companyId,
                $note->contactId,
                $parsed->receipt,
                $schedule->clockStart,
                $schedule->replyDue,
                $parsed->receiptSource,
                $note->body,
                $draft?->subject,
                $draft?->body,
                $earlierByCompany[$note->companyId] ?? [],
            );
        }

        if ($overdue !== null) {
            $open = array_values(array_filter(
                $open,
                fn (OpenReply $reply): bool => $reply->overdueAt($now) === $overdue,
            ));
        }

        return $open;
    }
}
