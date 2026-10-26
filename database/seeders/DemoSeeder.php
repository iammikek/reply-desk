<?php

namespace Database\Seeders;

use App\Clock\ReceiptSource;
use App\Clock\ReplyClock;
use App\Desk\Actions\LogInbound;
use App\Desk\Actions\MarkReplySent;
use App\Desk\Actions\SaveDraft;
use App\Desk\DeskException;
use App\Models\Company;
use App\Models\Contact;
use DateTimeImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (config('reply-desk.store') !== 'memory') {
            throw new DeskException('The demo seed only runs on the memory store.');
        }

        if (DB::table('emails')->count() > 0) {
            throw new DeskException('The demo queue is already seeded.');
        }

        $now = DateTimeImmutable::createFromInterface(now(ReplyClock::ZONE));

        $northwharf = Company::query()->create(['name' => 'Northwharf Bakery']);
        Contact::query()->create([
            'company_id' => $northwharf->id,
            'name' => 'Ada North',
            'email' => 'ada@northwharf.example',
        ]);
        $harbour = Company::query()->create(['name' => 'Harbour Cafe']);
        Contact::query()->create([
            'company_id' => $harbour->id,
            'name' => 'Ned Shore',
            'email' => 'ned@harbour.example',
        ]);
        $riverside = Company::query()->create(['name' => 'Riverside Books']);
        Contact::query()->create([
            'company_id' => $riverside->id,
            'name' => 'Jo River',
            'email' => 'jo@riverside.example',
        ]);

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

        $answered = $this->answeredReceipt($now);
        $closed = app(LogInbound::class)(
            'Riverside Books',
            'jo@riverside.example',
            $answered,
            ReceiptSource::MailClient,
            'Reprint of the spring catalogue',
            "Can you reprint the spring catalogue for the shop?\n\nJo",
        );
        app(MarkReplySent::class)(
            $closed,
            $answered->modify('+2 hours +30 minutes'),
            'Spring catalogue',
            'Yes. The reprint can go next week.',
        );
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

    private function answeredReceipt(DateTimeImmutable $now): DateTimeImmutable
    {
        $clock = app(ReplyClock::class);
        $candidate = $now->setTime(9, 0)->modify('-2 days');

        for ($day = 0; $day < 14; $day++) {
            $schedule = $clock->schedule($candidate);

            if ($schedule->clockStart == $candidate && $schedule->replyDue < $now) {
                return $candidate;
            }

            $candidate = $candidate->modify('-1 day');
        }

        throw new DeskException('Could not place an answered demo row.');
    }
}
