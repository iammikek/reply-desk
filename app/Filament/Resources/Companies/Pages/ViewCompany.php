<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\DirectoryRecords;
use App\Filament\Resources\Companies\CompanyResource;
use App\Models\Company;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

class ViewCompany extends ViewRecord
{
    protected static string $resource = CompanyResource::class;

    #[Locked]
    public string $directoryId = '';

    public function mount(int|string $record): void
    {
        $this->directoryId = (string) $record;

        parent::mount($record);
    }

    protected function resolveRecord(int|string $key): Model
    {
        $row = DirectoryRecords::company((string) $key);

        abort_if($row === null, 404);

        if (config('reply-desk.store') !== 'hubspot') {
            $company = Company::query()->find($key);

            if ($company instanceof Company) {
                return $company;
            }
        }

        $company = new Company(['name' => $row['name']]);
        $company->setAttribute('id', $row['id']);

        return $company;
    }

    protected function resolveRecordFromLivewire(Model $model): Model
    {
        $key = $model->getKey();

        if (! is_int($key) && (! is_string($key) || $key === '')) {
            $key = $this->directoryId;
        }

        abort_unless(is_int($key) || (is_string($key) && $key !== ''), 404);

        return $this->resolveRecord($key);
    }
}
