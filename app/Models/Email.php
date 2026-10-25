<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Email extends Model
{
    protected $fillable = [
        'company_id',
        'contact_id',
        'direction',
        'subject',
        'summary',
        'body',
        'at',
        'receipt_source',
        'closes_id',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function closes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'closes_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'closes_id');
    }

    public function draft(): HasOne
    {
        return $this->hasOne(Draft::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    protected function casts(): array
    {
        return [
            'at' => 'datetime',
        ];
    }
}
