<?php

namespace App\Filament\Pages;

use App\Desk\Queue\ListClientCorrespondence;
use Filament\Pages\Page;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;

class Client extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'clients/{company}/{contact}';

    protected static ?string $title = 'Client';

    protected Width|string|null $maxContentWidth = Width::Full;

    public string $company = '';

    public string $contact = '';

    /**
     * @var array{client: string, email: string, replies: list<array<string, mixed>>, outreach: list<array<string, mixed>>}|null
     */
    public ?array $correspondence = null;

    public function mount(string $company, string $contact): void
    {
        $this->company = $company;
        $this->contact = $contact;

        $found = app(ListClientCorrespondence::class)($company, $contact);

        if ($found === null) {
            abort(404);
        }

        $this->correspondence = [
            'client' => $found->client,
            'email' => $found->email,
            'replies' => $found->replies(),
            'outreach' => $found->outreach(),
        ];
    }

    public function getTitle(): string
    {
        return $this->correspondence['client'] ?? 'Client';
    }

    public function getSubheading(): ?string
    {
        $email = $this->correspondence['email'] ?? '';

        return $email !== '' ? $email : null;
    }

    public static function urlFor(string $companyId, string $contactId): string
    {
        return static::getUrl([
            'company' => $companyId,
            'contact' => $contactId,
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.client-threads')
                ->viewData([
                    'client' => $this->correspondence['client'] ?? '',
                    'email' => $this->correspondence['email'] ?? '',
                    'replies' => $this->correspondence['replies'] ?? [],
                    'outreach' => $this->correspondence['outreach'] ?? [],
                    'showClient' => false,
                ]),
        ]);
    }
}
