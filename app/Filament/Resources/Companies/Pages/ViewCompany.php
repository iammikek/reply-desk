<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Concerns\ResolvesExternalRecord;
use App\Filament\DirectoryRecords;
use App\Filament\Resources\Companies\CompanyResource;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewCompany extends ViewRecord
{
    use ResolvesExternalRecord;

    protected static string $resource = CompanyResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        $company = DirectoryRecords::companyModel((string) $key);

        abort_if($company === null, 404);

        return $company;
    }
}
