<?php

namespace App\Desk\Backfill;

use App\Clock\ClockNote;
use App\Clock\ReceiptSource;
use App\Clock\ReplyClock;
use App\Desk\DeskException;
use DateTimeImmutable;
use DateTimeZone;

final class OpenTable
{
    private const string OUTREACH_SUMMARY = 'Outreach started';

    /**
     * @return list<OpenTableRow>
     */
    public function rows(string $markdown): array
    {
        $section = $this->section($markdown, 'Open');
        $table = $this->table($section);

        if ($table === []) {
            throw new DeskException('The Open section has no table.');
        }

        $header = array_map(fn (string $cell): string => strtolower($cell), array_shift($table));
        $columns = array_flip($header);

        foreach (['client', 'inbound', 'reply sent', 'attachment'] as $name) {
            if (! array_key_exists($name, $columns)) {
                throw new DeskException("The Open table needs a {$name} column.");
            }
        }

        $rows = [];

        foreach ($table as $cells) {
            $sent = $cells[$columns['reply sent']] ?? '';

            if (trim($sent) !== '') {
                continue;
            }

            $rows[] = $this->row(
                $cells[$columns['client']] ?? '',
                $cells[$columns['inbound']] ?? '',
                $cells[$columns['attachment']] ?? '',
            );
        }

        return $rows;
    }

    /**
     * @return array{rows: list<SentTableRow>, skipped: list<string>}
     */
    public function sent(string $markdown): array
    {
        $section = $this->section($markdown, 'Sent', required: false);

        if ($section === '') {
            return ['rows' => [], 'skipped' => []];
        }

        $table = $this->table($section);

        if ($table === []) {
            return ['rows' => [], 'skipped' => []];
        }

        $header = array_map(fn (string $cell): string => strtolower($cell), array_shift($table));
        $columns = array_flip($header);

        foreach (['client', 'inbound', 'reply sent', 'attachment'] as $name) {
            if (! array_key_exists($name, $columns)) {
                throw new DeskException("The Sent table needs a {$name} column.");
            }
        }

        $rows = [];
        $skipped = [];

        foreach ($table as $cells) {
            $sent = trim($cells[$columns['reply sent']] ?? '');

            if ($sent === '') {
                continue;
            }

            try {
                $rows[] = $this->sentRow(
                    $cells[$columns['client']] ?? '',
                    $cells[$columns['inbound']] ?? '',
                    $sent,
                    $cells[$columns['attachment']] ?? '',
                );
            } catch (DeskException $exception) {
                $skipped[] = $exception->getMessage();
            }
        }

        return ['rows' => $rows, 'skipped' => $skipped];
    }

    /**
     * @return list<string>
     */
    public function preview(string $markdown): array
    {
        $lines = [];

        foreach ($this->rows($markdown) as $row) {
            $lines[] = $row->company;
            $lines[] = $row->email;
            $lines[] = ClockNote::inbound($row->receipt, $row->receiptSource, $row->summary);
            array_push($lines, ...$this->mailLines($row));
            $lines[] = '';
        }

        $sent = $this->sent($markdown);

        $seen = [];

        foreach ($sent['rows'] as $row) {
            $key = $row->company.'|'.$row->receipt->setTimezone(new DateTimeZone(ReplyClock::ZONE))->format('Y-m-d H:i');

            if (! isset($seen[$key])) {
                $lines[] = $row->company;
                $lines[] = $row->email;
                $lines[] = ClockNote::inbound($row->receipt, $row->receiptSource, $row->summary);

                if ($row->clientEmailPath !== null) {
                    $lines[] = 'Client email: '.basename($row->clientEmailPath);
                }

                $seen[$key] = true;
            }

            if ($row->replyPath !== null) {
                $lines[] = 'Reply: '.basename($row->replyPath);
            }

            $lines[] = implode("\n", [
                'Clock: sent',
                'Closes: the inbound above',
                'Sent: '.$row->sentAt->setTimezone(new DateTimeZone(ReplyClock::ZONE))->format('Y-m-d H:i').' Europe/London',
                'Subject: '.$row->subject,
            ]);
            $lines[] = '';
        }

        foreach ($sent['skipped'] as $message) {
            $lines[] = 'Skipped: '.$message;
        }

        $lines[] = 'Dry run. Nothing was written.';

        return $lines;
    }

    private function section(string $markdown, string $heading, bool $required = true): string
    {
        $lines = preg_split("/\r\n|\n|\r/", $markdown) ?: [];
        $capturing = false;
        $section = [];

        foreach ($lines as $line) {
            if (preg_match('/^##\s+(.*)$/', $line, $matches) === 1) {
                if ($capturing) {
                    break;
                }

                $capturing = strcasecmp(trim($matches[1]), $heading) === 0;

                continue;
            }

            if ($capturing) {
                $section[] = $line;
            }
        }

        if (! $capturing) {
            if (! $required) {
                return '';
            }

            throw new DeskException("No {$heading} section in that file.");
        }

        return implode("\n", $section);
    }

    /**
     * @return list<list<string>>
     */
    private function table(string $section): array
    {
        $rows = [];
        $started = false;

        foreach (preg_split("/\r\n|\n|\r/", $section) ?: [] as $line) {
            if (! str_starts_with(trim($line), '|')) {
                if ($started) {
                    break;
                }

                continue;
            }

            $cells = array_map(trim(...), explode('|', trim($line)));

            if ($cells !== [] && $cells[0] === '') {
                array_shift($cells);
            }

            if ($cells !== [] && $cells[array_key_last($cells)] === '') {
                array_pop($cells);
            }

            if ($cells !== [] && preg_match('/^-+$/', $cells[0]) === 1) {
                continue;
            }

            $started = true;
            $rows[] = $cells;
        }

        return $rows;
    }

    private function row(string $client, string $inbound, string $attachment): OpenTableRow
    {
        $parts = explode(' / ', $client, 2);
        $company = trim($parts[0]);

        if ($company === '') {
            throw new DeskException('An open row has no company.');
        }

        if (preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $attachment, $matches) < 1) {
            throw new DeskException("{$company} has no email address.");
        }

        $email = strtolower($matches[0][array_key_last($matches[0])]);

        $receipt = $this->moment($inbound);

        if (! $receipt instanceof DateTimeImmutable) {
            throw new DeskException("{$company} has no receipt time.");
        }

        $source = str_contains($inbound, 'mail-client time not on the paste')
            ? ReceiptSource::Logged
            : ReceiptSource::MailClient;

        $summary = preg_replace('/^\d{1,2} [A-Za-z]{3} \d{4} \d{2}:\d{2}/', '', trim($inbound)) ?? '';
        $summary = preg_replace('/^\s*\([^)]*\)\.?\s*/', '', $summary) ?? '';
        $summary = trim(preg_replace('/\s+/', ' ', $summary) ?? '');

        if ($summary === '') {
            $summary = 'Open reply';
        }

        $paths = $this->filedPaths($attachment);

        return new OpenTableRow($company, $email, $receipt, $source, $summary, $paths['client'], $paths['reply']);
    }

    private function sentRow(string $client, string $inbound, string $sent, string $attachment): SentTableRow
    {
        $parts = explode(' / ', $client, 2);
        $company = trim($parts[0]);

        if ($company === '') {
            throw new DeskException('A sent row has no company.');
        }

        if (preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $attachment, $matches) < 1) {
            throw new DeskException("{$company} has no email address.");
        }

        $email = strtolower($matches[0][array_key_last($matches[0])]);
        $sentAt = $this->moment($sent);

        if (! $sentAt instanceof DateTimeImmutable) {
            throw new DeskException("{$company} has no sent time.");
        }

        $wroteFirst = str_starts_with(strtolower(trim($inbound)), 'none');
        $receipt = $this->moment($inbound);

        if (! $receipt instanceof DateTimeImmutable) {
            if (! $wroteFirst) {
                throw new DeskException("{$company} has no receipt time.");
            }

            $receipt = $sentAt;
        }

        $source = str_contains($inbound, 'mail-client time not on the paste') || $wroteFirst
            ? ReceiptSource::Logged
            : ReceiptSource::MailClient;

        if ($wroteFirst) {
            $summary = self::OUTREACH_SUMMARY;
        } else {
            $summary = preg_replace('/^\d{1,2} [A-Za-z]{3} \d{4}(?: \d{2}:\d{2})?/', '', trim($inbound)) ?? '';
            $summary = preg_replace('/^\s*\([^)]*\)\.?\s*/', '', $summary) ?? '';
            $summary = ltrim(trim(preg_replace('/\s+/', ' ', $summary) ?? ''), " \t,.");

            if ($summary === '') {
                $summary = 'Open reply';
            }
        }

        $paths = $this->filedPaths($attachment);

        return new SentTableRow($company, $email, $receipt, $source, $summary, $sentAt, $this->subject($attachment, $summary), $paths['client'], $paths['reply']);
    }

    /**
     * @return array{client: ?string, reply: ?string}
     */
    private function filedPaths(string $attachment): array
    {
        $paths = ['client' => null, 'reply' => null];

        if (preg_match_all('/\[([^\]]+)\]\(([^)\s]+)\)/', $attachment, $matches, PREG_SET_ORDER) < 1) {
            return $paths;
        }

        foreach ($matches as $match) {
            $role = $this->mailRole($match[1]);

            if ($role !== null) {
                $paths[$role] = $match[2];
            }
        }

        return $paths;
    }

    private function mailRole(string $label): ?string
    {
        $label = strtolower(trim($label));

        if (in_array($label, ['client email', 'his email', 'her email', 'his note', 'her note', 'yes'], true)) {
            return 'client';
        }

        if (in_array($label, ['reply', 'sent reply', 'cover', '£80 audit', 'php support note'], true)) {
            return 'reply';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function mailLines(OpenTableRow $row): array
    {
        $lines = [];

        if ($row->clientEmailPath !== null) {
            $lines[] = 'Client email: '.basename($row->clientEmailPath);
        }

        if ($row->replyPath !== null) {
            $lines[] = 'Reply: '.basename($row->replyPath);
        }

        return $lines;
    }

    private function subject(string $attachment, string $summary): string
    {
        $text = preg_replace('/\[[^\]]*\]\([^)]*\)/', ' ', $attachment) ?? $attachment;
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        $text = ltrim($text, " \t.");

        if (preg_match('/Subject\s+(.+?)(?:\. |$)/i', $text, $matches) === 1) {
            return $this->oneLine(rtrim($matches[1], '.'));
        }

        $text = preg_replace('/^None\.\s*/i', '', $text) ?? $text;
        $sentence = '';

        if (preg_match('/^(.+?)(?:\. |$)/', $text, $matches) === 1) {
            $sentence = rtrim(trim($matches[1]), '.');
        }

        if ($sentence !== '') {
            return $this->oneLine($sentence);
        }

        if ($summary !== '' && $summary !== 'Open reply' && $summary !== self::OUTREACH_SUMMARY) {
            return $this->oneLine($summary);
        }

        return 'Reply';
    }

    private function oneLine(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        if (mb_strlen($value) > 180) {
            $value = rtrim(mb_substr($value, 0, 180));
        }

        return $value === '' ? 'Reply' : $value;
    }

    private function moment(string $text): ?DateTimeImmutable
    {
        $text = trim($text);

        if (preg_match('/^(\d{1,2}) ([A-Za-z]{3}) (\d{4}) (\d{1,2}):(\d{2})/', $text, $stamp) === 1) {
            return $this->londonMoment($stamp[1], $stamp[2], $stamp[3], $stamp[4], $stamp[5]);
        }

        if (preg_match('/^(\d{1,2}) ([A-Za-z]{3}) (\d{4})\b/', $text, $stamp) === 1
            && preg_match('/\bat (\d{1,2}):(\d{2})/', $text, $clock) === 1) {
            return $this->londonMoment($stamp[1], $stamp[2], $stamp[3], $clock[1], $clock[2]);
        }

        return null;
    }

    private function londonMoment(string $day, string $monthName, string $year, string $hour, string $minute): ?DateTimeImmutable
    {
        $months = [
            'Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4,
            'May' => 5, 'Jun' => 6, 'Jul' => 7, 'Aug' => 8,
            'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12,
        ];
        $month = $months[$monthName] ?? null;

        if ($month === null) {
            return null;
        }

        $moment = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i',
            sprintf('%04d-%02d-%02d %02d:%02d', (int) $year, $month, (int) $day, (int) $hour, (int) $minute),
            new DateTimeZone(ReplyClock::ZONE),
        );

        return $moment === false ? null : $moment;
    }
}
