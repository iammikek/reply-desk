<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailMessageId extends Model
{
    protected $table = 'mail_message_ids';

    protected $fillable = [
        'message_id',
        'note_id',
        'kind',
    ];

    public static function normalize(string $messageId): string
    {
        return trim($messageId);
    }

    public static function remember(string $messageId, string $noteId, string $kind): void
    {
        $messageId = self::normalize($messageId);

        if ($messageId === '') {
            return;
        }

        self::query()->updateOrCreate(
            ['message_id' => $messageId],
            ['note_id' => $noteId, 'kind' => $kind],
        );
    }

    public static function findNoteId(string $messageId): ?string
    {
        $messageId = self::normalize($messageId);

        if ($messageId === '') {
            return null;
        }

        return self::query()->where('message_id', $messageId)->value('note_id');
    }

    public static function known(string $messageId): bool
    {
        return self::findNoteId($messageId) !== null;
    }
}
