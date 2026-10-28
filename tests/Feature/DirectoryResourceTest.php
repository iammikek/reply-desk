<?php

use App\Desk\Notes\HubSpotNoteStore;
use App\Desk\Notes\NoteStore;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\Companies\Pages\ViewCompany;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Contacts\Pages\ViewContact;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    seedReplyDeskDirectory();
    replyDeskParty('company', '11', 'Harbour Cafe');
    replyDeskParty('contact', '21', 'Ned Harbour', 'ned@harbour.example', '11');
});

it('keeps a signed-out visitor off the company and contact resources', function (): void {
    $this->get(CompanyResource::getUrl())->assertRedirect('/login');
    $this->get(ContactResource::getUrl())->assertRedirect('/login');
});

it('lists companies and only the contacts on the open company', function (): void {
    $this->actingAs(User::factory()->create());

    Livewire::test(ListCompanies::class)
        ->assertSee('Northwharf Bakery')
        ->assertSee('Harbour Cafe')
        ->assertDontSee('ada@northwharf.example');

    Livewire::test(ViewCompany::class, ['record' => '10'])
        ->assertSee('Northwharf Bakery')
        ->assertSee('Ada North')
        ->assertSee('ada@northwharf.example')
        ->assertDontSee('ned@harbour.example');

    Livewire::test(ListContacts::class)
        ->assertSee('Ada North')
        ->assertSee('Ned Harbour')
        ->assertSee('Harbour Cafe');
});

it('reads HubSpot companies without writing a local company', function (): void {
    config([
        'reply-desk.store' => 'hubspot',
        'reply-desk.portal_id' => '999',
    ]);

    Cache::put('reply-desk.directory.999', [[
        'id' => '455547014356',
        'name' => 'Spectra Stand-in',
        'contacts' => [[
            'id' => '81',
            'name' => 'Pat Example',
            'email' => 'pat@spectra.example',
        ]],
    ]], 60);

    $this->app->instance(NoteStore::class, $this->app->make(HubSpotNoteStore::class));
    $this->actingAs(User::factory()->create());

    Livewire::test(ListCompanies::class)
        ->assertSee('Spectra Stand-in')
        ->assertDontSee('Northwharf Bakery');

    DB::flushQueryLog();
    DB::enableQueryLog();

    Livewire::test(ViewCompany::class, ['record' => '455547014356'])
        ->assertSee('Spectra Stand-in')
        ->assertSee('pat@spectra.example')
        ->assertDontSee('ada@northwharf.example')
        ->call('$refresh')
        ->assertSee('Spectra Stand-in');

    Livewire::test(ViewContact::class, ['record' => '81'])
        ->assertSee('Pat Example')
        ->assertSee('Spectra Stand-in');

    $tables = collect(DB::getQueryLog())
        ->pluck('query')
        ->implode(' ');

    expect($tables)->not->toContain('companies')
        ->and($tables)->not->toContain('contacts')
        ->and(Company::query()->where('name', 'Spectra Stand-in')->exists())->toBeFalse();
});

it('adds a company and a contact on the local directory', function (): void {
    $this->actingAs(User::factory()->create());

    Livewire::test(ListCompanies::class)
        ->callAction('createCompany', ['name' => 'West Quay'])
        ->assertNotified('Company added')
        ->assertSee('West Quay');

    $companyId = (string) Company::query()->where('name', 'West Quay')->value('id');

    Livewire::test(ListContacts::class)
        ->callAction('createContact', [
            'company_id' => $companyId,
            'name' => 'Bea Quay',
            'email' => 'Bea@West.example',
        ])
        ->assertNotified('Contact added')
        ->assertSee('Bea Quay')
        ->assertSee('bea@west.example');

    Livewire::test(ViewCompany::class, ['record' => '10'])
        ->callAction('createContact', [
            'name' => 'Ida North',
            'email' => 'ida@northwharf.example',
        ])
        ->assertNotified('Contact added');

    Livewire::test(ViewCompany::class, ['record' => '10'])
        ->assertSee('Ida North')
        ->assertSee('ida@northwharf.example');

    expect(Contact::query()->where('email', 'bea@west.example')->value('name'))->toBe('Bea Quay');
});

it('refuses a company name that is already there', function (): void {
    $this->actingAs(User::factory()->create());

    Livewire::test(ListCompanies::class)
        ->callAction('createCompany', ['name' => 'Northwharf Bakery'])
        ->assertNotified('That company is already there.');

    expect(Company::query()->where('name', 'Northwharf Bakery')->count())->toBe(1);
});
