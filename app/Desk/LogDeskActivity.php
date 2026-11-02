<?php

declare(strict_types=1);

namespace App\Desk;

use App\Models\DeskActivity;
use Throwable;

final class LogDeskActivity
{
    public function __invoke(DeskActivityEntry $entry): void
    {
        try {
            DeskActivity::query()->create([
                'action' => $entry->action,
                'summary' => $entry->summary,
                'inbound_id' => $entry->inboundId,
                'note_id' => $entry->noteId,
                'company' => $entry->company,
                'contact' => $entry->contact,
                'company_id' => $entry->companyId,
                'contact_id' => $entry->contactId,
                'context' => $entry->context,
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // Activity must not block the desk action.
        }
    }
}
