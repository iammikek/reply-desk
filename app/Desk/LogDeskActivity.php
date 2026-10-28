<?php

namespace App\Desk;

use App\Models\DeskActivity;
use Throwable;

final class LogDeskActivity
{
    /**
     * @param  array<string, mixed>|null  $context
     */
    public function __invoke(
        string $action,
        string $summary,
        ?string $inboundId = null,
        ?string $noteId = null,
        ?string $company = null,
        ?string $contact = null,
        ?array $context = null,
    ): void {
        try {
            DeskActivity::query()->create([
                'action' => $action,
                'summary' => $summary,
                'inbound_id' => $inboundId,
                'note_id' => $noteId,
                'company' => $company,
                'contact' => $contact,
                'context' => $context,
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // Activity must not block the desk action.
        }
    }
}
