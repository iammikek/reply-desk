<?php

declare(strict_types=1);

namespace App\Desk;

final class InboundSummary
{
    /**
     * Prefer the subject; otherwise the first non-empty line of the body.
     */
    public static function from(string $subject, string $body): string
    {
        $subject = trim($subject);

        if ($subject !== '') {
            return $subject;
        }

        foreach (preg_split("/\r\n|\n|\r/", $body) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '') {
                return $line;
            }
        }

        return '';
    }
}
