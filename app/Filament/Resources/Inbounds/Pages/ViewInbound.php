<?php

namespace App\Filament\Resources\Inbounds\Pages;

use App\Filament\InboundRecords;
use App\Filament\Resources\Inbounds\InboundResource;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Email;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

class ViewInbound extends ViewRecord
{
    protected static string $resource = InboundResource::class;

    #[Locked]
    public string $inboundId = '';

    public function mount(int|string $record): void
    {
        $this->inboundId = (string) $record;

        parent::mount($record);
    }

    protected function resolveRecord(int|string $key): Model
    {
        $row = InboundRecords::find((string) $key);

        abort_if($row === null, 404);

        if (config('reply-desk.store') !== 'hubspot') {
            $email = Email::query()->with(['company', 'contact'])->find($key);

            if ($email instanceof Email) {
                return $email;
            }
        }

        $email = new Email([
            'summary' => $row['summary'],
            'subject' => $row['subject'] !== '' ? $row['subject'] : null,
            'body' => $row['body'],
            'at' => $row['at'],
            'company_id' => $row['company_id'],
            'contact_id' => $row['contact_id'],
            'direction' => 'inbound',
        ]);
        $email->setAttribute('id', $row['id']);
        $email->setRelation('company', new Company(['name' => $row['company']]));
        $email->setRelation('contact', new Contact([
            'name' => $row['contact'],
            'email' => $row['email'],
            'company_id' => $row['company_id'],
        ]));

        return $email;
    }

    protected function resolveRecordFromLivewire(Model $model): Model
    {
        $key = $model->getKey();

        if (! is_int($key) && (! is_string($key) || $key === '')) {
            $key = $this->inboundId;
        }

        abort_unless(is_int($key) || (is_string($key) && $key !== ''), 404);

        return $this->resolveRecord($key);
    }
}
