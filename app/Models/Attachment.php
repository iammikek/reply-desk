<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attachment extends Model
{
    protected $fillable = [
        'email_id',
        'name',
        'path',
    ];

    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }
}
