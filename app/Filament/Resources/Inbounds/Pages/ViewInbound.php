<?php

namespace App\Filament\Resources\Inbounds\Pages;

use App\Filament\Concerns\LoadsClientCorrespondence;
use App\Filament\Concerns\ResolvesExternalRecord;
use App\Filament\InboundRecords;
use App\Filament\Resources\Inbounds\InboundResource;
use App\Models\Email;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class ViewInbound extends ViewRecord
{
    use LoadsClientCorrespondence;
    use ResolvesExternalRecord {
        LoadsClientCorrespondence::mount insteadof ResolvesExternalRecord;
    }

    protected static string $resource = InboundResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        $email = InboundRecords::model((string) $key);

        abort_if(! $email instanceof Email, 404);

        return $email;
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function clientCorrespondenceParty(): array
    {
        return [
            (string) $this->getRecord()->getAttribute('company_id'),
            (string) $this->getRecord()->getAttribute('contact_id'),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return InboundResource::infolist($schema);
    }
}
