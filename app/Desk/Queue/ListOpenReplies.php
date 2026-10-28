<?php

namespace App\Desk\Queue;

use App\Clock\ClockNote;
use App\Clock\ReplyClock;
use App\Desk\Notes\NoteStore;
use App\Models\Draft;
use DateTimeImmutable;

final readonly class ListOpenReplies
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
        $now ??= DateTimeImmutable::createFromInterface(now(ReplyClock::zoneName()));
        ['inbound' => $inbound, 'sent' => $sent] = (new IndexClockNotes($this->notes))();

        $earlierByCompany = [];

        foreach ($inbound as [$note, $parsed]) {
            $replies = $sent[$note->id] ?? null;

            if ($replies === null) {
                continue;
            }

            $replies = IndexClockNotes::sortReplies($replies);

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
            usort($threads, fn (EarlierThread $left, EarlierThread $right): int => $left->receipt <=> $right->receipt);
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
            $draft = $drafts->get($note->id);

            $open[] = new OpenReply(
                $note->id,
                $note->clientLabel(),
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
