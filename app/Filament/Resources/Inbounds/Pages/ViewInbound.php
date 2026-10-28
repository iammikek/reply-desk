<?php

namespace App\Filament\Resources\Inbounds\Pages;

use App\Filament\Concerns\ResolvesExternalRecord;
use App\Filament\InboundRecords;
use App\Filament\Resources\Inbounds\InboundResource;
use App\Models\Email;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewInbound extends ViewRecord
{
    use ResolvesExternalRecord;

    protected static string $resource = InboundResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        $email = InboundRecords::model((string) $key);

        abort_if(! $email instanceof Email, 404);

        return $email;
    }
}
