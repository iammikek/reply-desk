<?php

namespace App\Desk\Queue;

use App\Clock\ClockNote;
use App\Clock\ParsedInbound;
use App\Clock\ParsedSent;
use App\Desk\Notes\NoteStore;
use App\Desk\Notes\StoredNote;

final readonly class IndexClockNotes
{
    public function __construct(private NoteStore $notes) {}

    /**
     * @return array{
     *     inbound: list<array{0: StoredNote, 1: ParsedInbound}>,
     *     sent: array<string, list<array{0: StoredNote, 1: ParsedSent}>>
     * }
     */
    public function __invoke(?string $companyId = null, ?string $contactId = null): array
    {
        $sent = [];
        $inbound = [];

        foreach ($this->notes->notes() as $note) {
            $parsed = ClockNote::parse($note->body);

            if ($parsed instanceof ParsedSent) {
                $sent[$parsed->closes][] = [$note, $parsed];
            }

            if (! $parsed instanceof ParsedInbound) {
                continue;
            }

            if ($companyId !== null && ($note->companyId !== $companyId || $note->contactId !== $contactId)) {
                continue;
            }

            $inbound[] = [$note, $parsed];
        }

        return ['inbound' => $inbound, 'sent' => $sent];
    }

    /**
     * @param  list<array{0: StoredNote, 1: ParsedSent}>  $replies
     * @return list<array{0: StoredNote, 1: ParsedSent}>
     */
    public static function sortReplies(array $replies): array
    {
        usort($replies, fn (array $left, array $right): int => $left[1]->sent <=> $right[1]->sent);

        return $replies;
    }
}
