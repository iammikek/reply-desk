<?php

namespace Database\Seeders;

use App\Clock\ReceiptSource;
use App\Clock\ReplyClock;
use App\Desk\Actions\InboundLogInput;
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

        $now = DateTimeImmutable::createFromInterface(now(ReplyClock::zoneName()));

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

        $flourAsked = $this->answeredReceipt($now)->modify('-2 days');
        $flour = app(LogInbound::class)(
            'Northwharf Bakery',
            'ada@northwharf.example',
            new InboundLogInput(
                $flourAsked,
                ReceiptSource::MailClient,
                'Stoneground flour delivery slipped a day.',
                "Hi Mike,\n\nOur mill says the stoneground wholemeal will arrive a day late. Can we still cover the wholesale trays with what we have in the store, or should I add a second bag of strong white?\n\nThanks,\nAda",
                subject: 'Stoneground flour delivery',
            ),
        );
        app(MarkReplySent::class)(
            $flour,
            $flourAsked->modify('+3 hours'),
            'Re: Stoneground flour delivery',
            "Hi Ada,\n\nUse the strong white for the batch loaves and keep the stoneground for the counter tin. That should cover the trays without a second bag.\n\nMike",
        );

        $overdue = app(LogInbound::class)(
            'Northwharf Bakery',
            'ada@northwharf.example',
            new InboundLogInput(
                $this->overdueReceipt($now),
                ReceiptSource::MailClient,
                'Cafe wholesale needs twenty extra wholemeal loaves.',
                "Hi Mike,\n\nThe cafe order is up again. Can we add twenty wholemeal tin loaves to the usual wholesale tray, and keep the seeded batch as it is?\n\nAda",
                subject: 'Wholesale tray for the cafe',
            ),
        );

        app(LogInbound::class)(
            'Harbour Cafe',
            'ned@harbour.example',
            new InboundLogInput(
                $now,
                ReceiptSource::Logged,
                'Weekend brunch board needs a short rewrite.',
                "Mike — the weekend brunch board still lists last month's specials. Can you send a short rewrite for Saturday and Sunday before we print the chalk cards?\n\nNed",
                subject: 'Weekend brunch board',
            ),
        );

        app(SaveDraft::class)(
            $overdue,
            'Re: Wholesale tray for the cafe',
            "Hi Ada,\n\nYes — twenty wholemeal tins on the wholesale tray. Seeded batch stays as planned. I will confirm quantities with the night bake.\n\nMike",
        );

        $answered = $this->answeredReceipt($now);
        $closed = app(LogInbound::class)(
            'Riverside Books',
            'jo@riverside.example',
            new InboundLogInput(
                $answered,
                ReceiptSource::MailClient,
                'Shop needs a reprint of the spring catalogue.',
                "Hi Mike,\n\nCan you reprint the spring catalogue for the shop floor? We are down to the last dozen and Saturday's signing will finish them.\n\nJo",
                subject: 'Spring catalogue reprint',
            ),
        );
        app(MarkReplySent::class)(
            $closed,
            $answered->modify('+2 hours +30 minutes'),
            'Re: Spring catalogue reprint',
            "Hi Jo,\n\nYes. The reprint can go next week — I will send a proof tomorrow and book the run once you are happy with the cover price line.\n\nMike",
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
