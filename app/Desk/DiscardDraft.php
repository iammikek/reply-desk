<?php

namespace App\Desk;

use App\Models\Draft;

final class DiscardDraft
{
    public function __invoke(string $inboundId): void
    {
        Draft::query()->where('inbound_note_id', $inboundId)->delete();
    }
}
