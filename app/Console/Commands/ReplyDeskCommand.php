<?php

namespace App\Console\Commands;

use App\Clock\ReceiptSource;
use App\Clock\ReplyClock;
use App\Desk\DeskException;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;

abstract class ReplyDeskCommand extends Command
{
    /**
     * @param  array<string, mixed>|list<mixed>  $payload
     */
    protected function json(array $payload, int $code = 0): int
    {
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $code;
    }

    protected function failure(DeskException $exception): int
    {
        return $this->json(['error' => $exception->getMessage()], 1);
    }

    protected function moment(string $value): DateTimeImmutable
    {
        $moment = DateTimeImmutable::createFromFormat('Y-m-d H:i', $value, new DateTimeZone(ReplyClock::ZONE));

        if ($moment === false) {
            throw new DeskException('A time must be Y-m-d H:i in Europe/London.');
        }

        return $moment;
    }

    protected function source(string $value): ReceiptSource
    {
        return ReceiptSource::tryFrom($value) ?? throw new DeskException('Receipt source must be "mail client" or "logged".');
    }
}
