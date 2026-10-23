<?php

namespace App\Clock;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class ClockNote
{
    public static function inbound(
        DateTimeImmutable $receipt,
        ReceiptSource $receiptSource,
        string $summary,
    ): string {
        $summary = self::oneLine($summary, 'Summary');

        return implode("\n", [
            'Clock: inbound',
            'Receipt: '.$receipt->setTimezone(self::zone())->format('Y-m-d H:i').' Europe/London',
            'Receipt source: '.$receiptSource->value,
            'Summary: '.$summary,
        ]);
    }

    public static function sent(string $closes, DateTimeImmutable $sent, string $subject): string
    {
        if (preg_match('/^\d+$/', $closes) !== 1) {
            throw new InvalidArgumentException('A sent note must name the inbound note id.');
        }

        $subject = self::oneLine($subject, 'Subject');

        return implode("\n", [
            'Clock: sent',
            'Closes: '.$closes,
            'Sent: '.$sent->setTimezone(self::zone())->format('Y-m-d H:i').' Europe/London',
            'Subject: '.$subject,
        ]);
    }

    public static function parse(string $body): ParsedInbound|ParsedSent|null
    {
        $lines = preg_split("/\r\n|\n|\r/", trim($body)) ?: [];

        return match ($lines[0] ?? '') {
            'Clock: inbound' => self::parseInbound($lines),
            'Clock: sent' => self::parseSent($lines),
            default => null,
        };
    }

    /**
     * @param  list<string>  $lines
     */
    private static function parseInbound(array $lines): ?ParsedInbound
    {
        if (count($lines) < 3 || count($lines) > 4) {
            return null;
        }

        $receipt = self::moment($lines[1], 'Receipt');
        $source = self::source($lines[2]);

        if ($receipt === null || $source === null) {
            return null;
        }

        $summary = '';

        if (count($lines) === 4) {
            if (preg_match('/^Summary: (.+)$/', $lines[3], $matches) !== 1) {
                return null;
            }

            $summary = $matches[1];
        }

        return new ParsedInbound($receipt, $source, $summary);
    }

    /**
     * @param  list<string>  $lines
     */
    private static function parseSent(array $lines): ?ParsedSent
    {
        if (count($lines) !== 4) {
            return null;
        }

        if (preg_match('/^Closes: (\d+)$/', $lines[1], $closes) !== 1) {
            return null;
        }

        $sent = self::moment($lines[2], 'Sent');

        if ($sent === null || preg_match('/^Subject: (.+)$/', $lines[3], $subject) !== 1) {
            return null;
        }

        return new ParsedSent($closes[1], $sent, $subject[1]);
    }

    private static function moment(string $line, string $label): ?DateTimeImmutable
    {
        if (preg_match('/^'.preg_quote($label, '/').': (\d{4}-\d{2}-\d{2} \d{2}:\d{2}) Europe\/London$/', $line, $matches) !== 1) {
            return null;
        }

        $moment = DateTimeImmutable::createFromFormat('Y-m-d H:i', $matches[1], self::zone());

        if ($moment === false) {
            return null;
        }

        return $moment;
    }

    private static function source(string $line): ?ReceiptSource
    {
        if (preg_match('/^Receipt source: (.+)$/', $line, $matches) !== 1) {
            return null;
        }

        return ReceiptSource::tryFrom($matches[1]);
    }

    private static function oneLine(string $value, string $label): string
    {
        $value = trim($value);

        if ($value === '' || str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new InvalidArgumentException($label.' must be one line.');
        }

        return $value;
    }

    private static function zone(): DateTimeZone
    {
        return new DateTimeZone(ReplyClock::ZONE);
    }
}
