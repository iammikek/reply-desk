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
        string $summary = '',
        string $subject = '',
    ): string {
        $lines = [
            'Clock: inbound',
            'Receipt: '.$receipt->setTimezone(self::zone())->format('Y-m-d H:i').' Europe/London',
            'Receipt source: '.$receiptSource->value,
        ];

        if (trim($subject) !== '') {
            $lines[] = 'Subject: '.self::oneLine($subject, 'Subject');
        }

        if (trim($summary) !== '') {
            $lines[] = 'Summary: '.self::oneLine($summary, 'Summary');
        }

        return implode("\n", $lines);
    }

    public static function sent(string $closes, DateTimeImmutable $sent, string $subject, string $reply = ''): string
    {
        if (preg_match('/^\d+$/', $closes) !== 1) {
            throw new InvalidArgumentException('A sent note must name the inbound note id.');
        }

        $lines = [
            'Clock: sent',
            'Closes: '.$closes,
            'Sent: '.$sent->setTimezone(self::zone())->format('Y-m-d H:i').' Europe/London',
        ];
        $subject = trim($subject);

        if ($subject !== '') {
            $lines[] = 'Subject: '.self::oneLine($subject, 'Subject');
        }

        $body = implode("\n", $lines);

        return self::withMail($body, reply: $reply);
    }

    public static function subjectOf(string $body): string
    {
        foreach (self::header($body) as $line) {
            if (preg_match('/^Subject: (.+)$/', $line, $matches) === 1) {
                return $matches[1];
            }
        }

        return '';
    }

    public static function parse(string $body): ParsedInbound|ParsedSent|null
    {
        $lines = self::header($body);

        return match ($lines[0] ?? '') {
            'Clock: inbound' => self::parseInbound($lines),
            'Clock: sent' => self::parseSent($lines),
            default => null,
        };
    }

    /**
     * Record the email subject and the message. The summary line stays.
     * A new message replaces the section already on the note.
     */
    public static function applyMail(string $body, string $clientEmail = '', string $reply = '', string $subject = ''): string
    {
        $lines = self::header($body);
        $kind = $lines[0] ?? '';

        if ($subject !== '' && $kind === 'Clock: inbound') {
            $written = false;

            foreach ($lines as $index => $line) {
                if (str_starts_with($line, 'Subject:')) {
                    $lines[$index] = 'Subject: '.self::oneLine($subject, 'Subject');
                    $written = true;
                }
            }

            if (! $written) {
                $lines[] = 'Subject: '.self::oneLine($subject, 'Subject');
            }
        }

        if ($subject !== '' && $kind === 'Clock: sent') {
            foreach ($lines as $index => $line) {
                if (str_starts_with($line, 'Subject:')) {
                    $lines[$index] = 'Subject: '.self::oneLine($subject, 'Subject');
                }
            }
        }

        $existing = self::correspondence($body);
        $clientEmail = $clientEmail !== '' ? self::pasted($clientEmail) : $existing['client'];
        $reply = $reply !== '' ? self::pasted($reply) : $existing['reply'];
        $header = implode("\n", $lines);

        if ($clientEmail !== '') {
            $header .= "\n\nClient email:\n".$clientEmail;
        }

        if ($reply !== '') {
            $header .= "\n\nReply:\n".$reply;
        }

        return $header;
    }

    /**
     * Keep any mail already on the note. Fill a section only when it is empty.
     */
    public static function withMail(string $body, string $clientEmail = '', string $reply = ''): string
    {
        $existing = self::correspondence($body);
        $clientEmail = $existing['client'] !== '' ? $existing['client'] : self::pasted($clientEmail);
        $reply = $existing['reply'] !== '' ? $existing['reply'] : self::pasted($reply);
        $header = implode("\n", self::header($body));

        if ($clientEmail !== '') {
            $header .= "\n\nClient email:\n".$clientEmail;
        }

        if ($reply !== '') {
            $header .= "\n\nReply:\n".$reply;
        }

        return $header;
    }

    /**
     * @return array{client: string, reply: string}
     */
    public static function correspondence(string $body): array
    {
        return [
            'client' => self::section($body, 'Client email'),
            'reply' => self::section($body, 'Reply'),
        ];
    }

    /**
     * @return list<string>
     */
    private static function header(string $body): array
    {
        $lines = preg_split("/\r\n|\n|\r/", trim($body)) ?: [];
        $header = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                break;
            }

            $header[] = $line;
        }

        return $header;
    }

    /**
     * A blank message is empty. Anything else keeps its blank lines, indentation, and runs of spaces.
     */
    public static function pasted(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        return trim($value) === '' ? '' : $value;
    }

    private static function section(string $body, string $heading): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $pattern = '/^'.preg_quote($heading, '/').':\n(.*?)(?=\n(Client email|Reply):\n|\z)/ms';

        if (preg_match($pattern, $body, $matches) !== 1) {
            return '';
        }

        $section = $matches[1];

        if (($matches[2] ?? '') !== '' && str_ends_with($section, "\n")) {
            $section = substr($section, 0, -1);
        }

        return $section;
    }

    /**
     * @param  list<string>  $lines
     */
    private static function parseInbound(array $lines): ?ParsedInbound
    {
        if (count($lines) < 3 || count($lines) > 5) {
            return null;
        }

        $receipt = self::moment($lines[1], 'Receipt');
        $source = self::source($lines[2]);

        if ($receipt === null || $source === null) {
            return null;
        }

        $summary = '';
        $subject = '';

        foreach (array_slice($lines, 3) as $line) {
            if (preg_match('/^Summary: (.+)$/', $line, $matches) === 1) {
                $summary = $matches[1];

                continue;
            }

            if (preg_match('/^Subject: (.+)$/', $line, $matches) === 1) {
                $subject = $matches[1];

                continue;
            }

            return null;
        }

        return new ParsedInbound($receipt, $source, $summary !== '' ? $summary : $subject);
    }

    /**
     * @param  list<string>  $lines
     */
    private static function parseSent(array $lines): ?ParsedSent
    {
        if (count($lines) < 3 || count($lines) > 4) {
            return null;
        }

        if (preg_match('/^Closes: (\d+)$/', $lines[1], $closes) !== 1) {
            return null;
        }

        $sent = self::moment($lines[2], 'Sent');
        $subject = '';

        if ($sent === null) {
            return null;
        }

        if (isset($lines[3]) && preg_match('/^Subject: (.+)$/', $lines[3], $matches) !== 1) {
            return null;
        }

        if (isset($lines[3])) {
            $subject = $matches[1];
        }

        return new ParsedSent($closes[1], $sent, $subject);
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
