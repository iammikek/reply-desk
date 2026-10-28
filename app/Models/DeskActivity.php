<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeskActivity extends Model
{
    public $timestamps = false;

    protected $table = 'desk_activity';

    protected $fillable = [
        'action',
        'summary',
        'inbound_id',
        'note_id',
        'company',
        'contact',
        'company_id',
        'contact_id',
        'context',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
