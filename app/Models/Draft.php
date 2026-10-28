<?php

namespace App\Models;

use App\Desk\DeskException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Draft extends Model
{
    protected $fillable = [
        'email_id',
        'inbound_note_id',
        'subject',
        'body',
        'mailbox_draft_id',
    ];

    public static function inboundColumn(): string
    {
        return config('reply-desk.store') === 'hubspot' ? 'inbound_note_id' : 'email_id';
    }

    /**
     * @return array{email_id?: string, inbound_note_id?: string}
     */
    public static function forInbound(string $inboundId): array
    {
        return [self::inboundColumn() => $inboundId];
    }

    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }

    protected static function booted(): void
    {
        static::saving(function (Draft $draft): void {
            $email = $draft->email_id !== null && $draft->email_id !== '';
            $note = $draft->inbound_note_id !== null && $draft->inbound_note_id !== '';

            if ($email === $note) {
                throw new DeskException('A draft points at the local email or the HubSpot note.');
            }
        });
    }
}
