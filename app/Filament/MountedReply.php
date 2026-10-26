<?php

namespace App\Filament;

use App\Desk\SavedDraft;
use Filament\Actions\Action;

final class MountedReply
{
    /**
     * A nested cancel action does not receive the parent row. The reply
     * form and its record key stay on the parent mounted action.
     *
     * @return array<string, mixed>
     */
    public static function form(Action $action): array
    {
        $data = $action->getLivewire()->mountedActions[self::parentIndex($action)]['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    public static function recordKey(Action $action): string
    {
        $context = $action->getLivewire()->mountedActions[self::parentIndex($action)]['context'] ?? [];

        return (string) (is_array($context) ? ($context['recordKey'] ?? '') : '');
    }

    public static function asksToSave(Action $action): bool
    {
        $data = self::form($action);
        $body = (string) ($data['body'] ?? '');

        if (trim($body) === '') {
            return false;
        }

        return ! SavedDraft::unchanged(self::recordKey($action), (string) ($data['subject'] ?? ''), $body);
    }

    private static function parentIndex(Action $action): int
    {
        $index = $action->getNestingIndex();

        return is_int($index) && $index > 0 ? $index - 1 : 0;
    }
}
