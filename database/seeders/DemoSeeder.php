<?php

namespace Database\Seeders;

use App\Clock\ReceiptSource;
use App\Clock\ReplyClock;
use App\Desk\Actions\LogInbound;
use App\Desk\Actions\SaveDraft;
use App\Desk\DeskException;
use DateTimeImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (config('reply-desk.store') !== 'memory' || (string) config('reply-desk.portal_id') === '145255358') {
            throw new DeskException('The demo seed only runs on the memory store, and never against portal 145255358.');
        }

        if (DB::table('fake_notes')->count() > 0) {
            throw new DeskException('The demo queue is already seeded.');
        }

        $now = DateTimeImmutable::createFromInterface(now(ReplyClock::ZONE));

        $this->party('company', '10', 'Northwharf Bakery', '');
        $this->party('contact', '20', 'Ada North', 'ada@northwharf.example', '10');
        $this->party('company', '11', 'Harbour Cafe', '');
        $this->party('contact', '21', 'Ned Shore', 'ned@harbour.example', '11');

        $overdue = app(LogInbound::class)(
            'Northwharf Bakery',
            'ada@northwharf.example',
            $this->overdueReceipt($now),
            ReceiptSource::MailClient,
            'Which PHP to use',
        );

        app(LogInbound::class)(
            'Harbour Cafe',
            'ned@harbour.example',
            $now,
            ReceiptSource::Logged,
            'Menu for the weekend',
        );

        app(SaveDraft::class)($overdue, 'PHP on the new server', 'Use 8.3 on the new box.');
    }

    private function overdueReceipt(DateTimeImmutable $now): DateTimeImmutable
    {
        $clock = app(ReplyClock::class);
        $candidate = $now->setTime(10, 0)->modify('-1 day');

        for ($day = 0; $day < 14; $day++) {
            if ($clock->schedule($candidate)->replyDue < $now) {
                return $candidate;
            }

            $candidate = $candidate->modify('-1 day');
        }

        throw new DeskException('Could not place an overdue demo row.');
    }

    private function party(string $kind, string $id, string $name, string $email, ?string $companyId = null): void
    {
        $existing = DB::table('fake_parties')->where('kind', $kind)->where('id', $id)->exists();

        DB::table('fake_parties')->updateOrInsert(
            ['kind' => $kind, 'id' => $id],
            [
                'name' => $name,
                'email' => $email,
                'company_id' => $companyId,
                'updated_at' => now(),
                ...($existing ? [] : ['created_at' => now()]),
            ],
        );
    }
}
