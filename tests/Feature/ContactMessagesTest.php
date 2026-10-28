<?php

use App\Clock\ReceiptSource;
use App\Desk\Actions\LogInbound;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    seedReplyDeskDirectory('2026-10-23 12:00');
    $this->actingAs(User::factory()->create());
});

it('shows messages on the contact page', function (): void {
    app(LogInbound::class)(
        'Northwharf Bakery',
        'ada@northwharf.example',
        london('2026-10-22 10:00'),
        ReceiptSource::MailClient,
        'Which PHP to use',
        'Can you look today?',
        subject: 'Which PHP to use',
    );

    $this->get('/contacts/20')
        ->assertSuccessful()
        ->assertSee('Ada North')
        ->assertSee('Messages')
        ->assertSee('Client email')
        ->assertSee('Can you look today?');
});

it('shows an empty messages state when the contact has none', function (): void {
    $this->get('/contacts/20')
        ->assertSuccessful()
        ->assertSee('No messages with this contact yet.');
});
