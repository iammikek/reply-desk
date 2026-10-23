<?php

namespace App\Filament\Widgets;

use App\Clock\ReplyClock;
use App\Desk\ListOpenReplies;
use App\Desk\OpenReply;
use DateTimeImmutable;
use DateTimeZone;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OpenQueueOverview extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected function getStats(): array
    {
        $now = DateTimeImmutable::createFromInterface(now(ReplyClock::ZONE));
        $open = app(ListOpenReplies::class)($now);
        $overdue = count(array_filter($open, fn (OpenReply $reply): bool => $reply->overdueAt($now)));
        $next = collect($open)->sortBy(fn (OpenReply $reply): int => $reply->replyDue->getTimestamp())->first();

        return [
            Stat::make('Open', (string) count($open)),
            Stat::make('Overdue', (string) $overdue)
                ->color($overdue > 0 ? 'danger' : 'gray'),
            Stat::make('Next due', $next instanceof OpenReply
                ? $next->replyDue->setTimezone(new DateTimeZone(ReplyClock::ZONE))->format('D j M H:i')
                : 'None'),
        ];
    }
}
