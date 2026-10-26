<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

trait ResolvesExternalRecord
{
    #[Locked]
    public string $externalRecordKey = '';

    public function mount(int|string $record): void
    {
        $this->externalRecordKey = (string) $record;

        parent::mount($record);
    }

    protected function resolveRecordFromLivewire(Model $model): Model
    {
        $key = $model->getKey();

        if (! is_int($key) && (! is_string($key) || $key === '')) {
            $key = $this->externalRecordKey;
        }

        abort_unless(is_int($key) || (is_string($key) && $key !== ''), 404);

        return $this->resolveRecord($key);
    }
}
