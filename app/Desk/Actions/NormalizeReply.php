<?php

declare(strict_types=1);

namespace App\Desk\Actions;

use App\Clock\ClockNote;
use App\Desk\DeskException;

final class NormalizeReply
{
    /**
     * @return array{0: string, 1: string}
     */
    public static function draft(string $subject, string $body): array
    {
        $subject = trim($subject);
        $body = ClockNote::pasted($body);

        if ($subject === '' || $body === '' || self::multiline($subject)) {
            throw new DeskException('A draft needs a subject and a body.');
        }

        return [$subject, $body];
    }

    public static function subject(string $subject): string
    {
        $subject = trim($subject);

        if (self::multiline($subject)) {
            throw new DeskException('Subject must be one line.');
        }

        return $subject;
    }

    public static function body(string $body): string
    {
        $body = ClockNote::pasted($body);

        if ($body === '') {
            throw new DeskException('A reply needs a body.');
        }

        return $body;
    }

    private static function multiline(string $subject): bool
    {
        return str_contains($subject, "\n") || str_contains($subject, "\r");
    }
}
