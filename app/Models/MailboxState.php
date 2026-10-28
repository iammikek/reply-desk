<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailboxState extends Model
{
    protected $table = 'mailbox_state';

    protected $fillable = [
        'polling_enabled',
        'poll_interval_minutes',
        'inbox_cursor',
        'inbox_uidvalidity',
        'last_polled_at',
        'last_poll_error',
    ];

    protected function casts(): array
    {
        return [
            'polling_enabled' => 'boolean',
            'poll_interval_minutes' => 'integer',
            'last_polled_at' => 'datetime',
        ];
    }

    public static function current(): self
    {
        $row = self::query()->first();

        if ($row instanceof self) {
            return $row;
        }

        return self::query()->create([
            'polling_enabled' => true,
            'poll_interval_minutes' => 5,
        ]);
    }

    public function dueForScheduledPoll(): bool
    {
        if (! $this->polling_enabled) {
            return false;
        }

        if ($this->last_polled_at === null) {
            return true;
        }

        return $this->last_polled_at->lte(now()->subMinutes(max(1, $this->poll_interval_minutes)));
    }
}
