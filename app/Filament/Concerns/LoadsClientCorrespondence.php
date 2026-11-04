<?php

namespace App\Filament\Concerns;

use App\Desk\DeskException;
use App\Desk\Queue\ListClientCorrespondence;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

trait LoadsClientCorrespondence
{
    /**
     * @var list<array<string, mixed>>
     */
    public array $replies = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $outreach = [];

    public function mount(int|string $record): void
    {
        $this->externalRecordKey = (string) $record;

        parent::mount($record);

        [$companyId, $contactId] = $this->clientCorrespondenceParty();

        try {
            $found = app(ListClientCorrespondence::class)($companyId, $contactId);
            $this->replies = $found?->replies() ?? [];
            $this->outreach = $found?->outreach() ?? [];
        } catch (DeskException) {
            // Page can render without a live note store (for example HubSpot token missing).
            $this->replies = [];
            $this->outreach = [];
        }

        $this->dispatch('refresh-sidebar');
    }

    public function content(Schema $schema): Schema
    {
        $empty = $this->replies === [] && $this->outreach === [];

        return $schema->components([
            EmbeddedSchema::make('infolist'),
            Section::make('Messages')->schema(
                $empty
                    ? [Text::make('No messages with this contact yet.')]
                    : [
                        View::make('filament.client-threads')
                            ->viewData([
                                'client' => '',
                                'email' => '',
                                'replies' => $this->replies,
                                'outreach' => $this->outreach,
                                'showClient' => false,
                            ]),
                    ],
            ),
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    abstract protected function clientCorrespondenceParty(): array;
}
