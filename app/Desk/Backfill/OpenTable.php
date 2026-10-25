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

        $this->requireColumns($columns, 'Open');

        $rows = [];

        foreach ($table as $cells) {
            if ($this->sentAtCell($columns, $cells) !== '') {
                continue;
            }

            $rows[] = $this->row(
                $cells[$columns['client']] ?? '',
                $cells[$columns['inbound']] ?? '',
                $this->cell($columns, $cells, 'clock start'),
                $this->mailCells($columns, $cells),
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

        $this->requireColumns($columns, 'Sent');

        $rows = [];
        $skipped = [];

        foreach ($table as $cells) {
            $sent = $this->sentAtCell($columns, $cells);

            if ($sent === '') {
                continue;
            }

            try {
                $rows[] = $this->sentRow(
                    $cells[$columns['client']] ?? '',
                    $cells[$columns['inbound']] ?? '',
                    $this->cell($columns, $cells, 'clock start'),
                    $sent,
                    $this->mailCells($columns, $cells),
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
    public function preview(string $markdown, string $baseDirectory = ''): array
    {
        $lines = [];

        foreach ($this->rows($markdown) as $row) {
            $client = $this->filed($baseDirectory, $row->clientEmailPath);
            $reply = $this->filed($baseDirectory, $row->replyPath);
            $lines[] = $row->company;
            $lines[] = $row->email;
            $lines[] = ClockNote::inbound($row->receipt, $row->receiptSource, $row->summary, $client?->subject ?? '');
            array_push($lines, ...$this->mailLines($row));

            if ($client instanceof FiledEmail) {
                $lines[] = $client->body;
            }

            if ($reply instanceof FiledEmail) {
                if ($reply->subject !== '') {
                    $lines[] = 'Subject: '.$reply->subject;
                }

                $lines[] = $reply->body;
            }

            $lines[] = '';
        }

        $sent = $this->sent($markdown);

        $seen = [];

        foreach ($sent['rows'] as $row) {
            $key = $row->company.'|'.$row->receipt->setTimezone(new DateTimeZone(ReplyClock::ZONE))->format('Y-m-d H:i');

            if (! isset($seen[$key])) {
                $client = $this->filed($baseDirectory, $row->clientEmailPath);
                $lines[] = $row->company;
                $lines[] = $row->email;
                $lines[] = ClockNote::inbound($row->receipt, $row->receiptSource, $row->summary, $client?->subject ?? '');

                if ($client instanceof FiledEmail) {
                    $lines[] = "Client email:\n".$client->body;
                } elseif ($row->clientEmailPath !== null) {
                    $lines[] = 'Client email: '.basename($row->clientEmailPath);
                }

                $seen[$key] = true;
            }

            $reply = $this->filed($baseDirectory, $row->replyPath);

            if ($row->replyPath !== null) {
                $lines[] = 'Reply: '.basename($row->replyPath);
            }

            $lines[] = implode("\n", [
                'Clock: sent',
                'Closes: the inbound above',
                'Sent: '.$row->sentAt->setTimezone(new DateTimeZone(ReplyClock::ZONE))->format('Y-m-d H:i').' Europe/London',
                'Subject: '.($reply instanceof FiledEmail && $reply->subject !== '' ? $reply->subject : $row->subject),
            ]);

            if ($reply instanceof FiledEmail) {
                $lines[] = $reply->body;
            }

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

    /**
     * @param  array<string, int>  $columns
     */
    private function requireColumns(array $columns, string $which): void
    {
        foreach (['client', 'inbound'] as $name) {
            if (! array_key_exists($name, $columns)) {
                throw new DeskException("The {$which} table needs a {$name} column.");
            }
        }

        if (! array_key_exists('replied on', $columns) && ! array_key_exists('reply sent', $columns)) {
            throw new DeskException("The {$which} table needs a replied on column.");
        }

        $split = (array_key_exists('inbound email', $columns) || array_key_exists('client email', $columns))
            && (array_key_exists('reply to', $columns) || array_key_exists('reply', $columns));

        if (! $split && ! array_key_exists('attachment', $columns)) {
            throw new DeskException("The {$which} table needs an inbound email column and a reply to column.");
        }
    }

    /**
     * @param  array<string, int>  $columns
     * @param  list<string>  $cells
     */
    private function cell(array $columns, array $cells, string $name): string
    {
        if (! array_key_exists($name, $columns)) {
            return '';
        }

        return $cells[$columns[$name]] ?? '';
    }

    /**
     * @param  array<string, int>  $columns
     * @param  list<string>  $cells
     */
    private function sentAtCell(array $columns, array $cells): string
    {
        if (array_key_exists('replied on', $columns)) {
            return trim($this->cell($columns, $cells, 'replied on'));
        }

        return trim($this->cell($columns, $cells, 'reply sent'));
    }

    /**
     * @param  array<string, int>  $columns
     * @param  list<string>  $cells
     * @return array{client: ?string, reply: ?string, text: string, address: string, notes: string}
     */
    private function mailCells(array $columns, array $cells): array
    {
        $clientEmail = $this->cell($columns, $cells, 'inbound email');

        if ($clientEmail === '') {
            $clientEmail = $this->cell($columns, $cells, 'client email');
        }
        $replyTo = $this->cell($columns, $cells, 'reply to');
        $replyEmail = $this->cell($columns, $cells, 'reply email');
        $replySummary = $this->cell($columns, $cells, 'reply summary');
        $replySent = array_key_exists('replied on', $columns)
            ? $this->cell($columns, $cells, 'replied on')
            : $this->cell($columns, $cells, 'reply sent');
        $reply = $this->cell($columns, $cells, 'reply');
        $attachment = $this->cell($columns, $cells, 'attachment');
        $client = $this->cell($columns, $cells, 'client');
        $text = trim($client.' '.$clientEmail.' '.$replySent.' '.$replyEmail.' '.$replySummary.' '.$replyTo.' '.$reply.' '.$attachment);
        $legacy = trim($reply.' '.$attachment);

        if (array_key_exists('reply summary', $columns)) {
            $notes = $replySummary;
        } elseif ($legacy !== '') {
            $notes = $legacy;
        } else {
            $notes = $this->replyNotes($replySent);
        }

        return [
            'client' => $this->firstLink($clientEmail) ?? $this->filedPaths($attachment)['client'],
            'reply' => $this->firstLink($replyEmail) ?? $this->firstLink($replySent) ?? $this->firstLink($reply) ?? $this->filedPaths($attachment)['reply'],
            'text' => $text,
            'address' => $this->address($replyTo, $text),
            'notes' => $notes,
        ];
    }

    private function replyNotes(string $replySent): string
    {
        $notes = preg_replace('/^\d{1,2} [A-Za-z]{3} \d{4}(?: \d{1,2}:\d{2})?/', '', trim($replySent)) ?? '';
        $notes = preg_replace('/^\s*\([^)]*\)\.?\s*/', '', $notes) ?? '';

        return trim($notes);
    }

    private function address(string $replyTo, string $text): string
    {
        $source = trim($replyTo);
        $useFirst = $source !== '';

        if (! $useFirst) {
            $source = $text;
        }

        if (preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $source, $matches) < 1) {
            return '';
        }

        $email = $useFirst ? $matches[0][0] : $matches[0][array_key_last($matches[0])];

        return strtolower($email);
    }

    private function firstLink(string $cell): ?string
    {
        if (preg_match('/\[[^\]]+\]\(([^)\s]+)\)/', $cell, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @param  array{client: ?string, reply: ?string, text: string, address: string, notes: string}  $mail
     */
    private function row(string $client, string $inbound, string $clock, array $mail): OpenTableRow
    {
        $parts = explode(' / ', $client, 2);
        $company = trim($parts[0]);

        if ($company === '') {
            throw new DeskException('An open row has no company.');
        }

        $email = $mail['address'];

        if ($email === '') {
            throw new DeskException("{$company} has no email address.");
        }

        $receipt = $this->moment($inbound) ?? $this->moment($clock);

        if (! $receipt instanceof DateTimeImmutable) {
            throw new DeskException("{$company} has no receipt time.");
        }

        $source = $this->logged($inbound.' '.$clock)
            ? ReceiptSource::Logged
            : ReceiptSource::MailClient;

        $summary = preg_replace('/^\d{1,2} [A-Za-z]{3} \d{4} \d{2}:\d{2}/', '', trim($inbound)) ?? '';
        $summary = preg_replace('/^\s*\([^)]*\)\.?\s*/', '', $summary) ?? '';
        $summary = trim(preg_replace('/\s+/', ' ', $summary) ?? '');

        if ($summary === '') {
            $summary = 'Open reply';
        }

        return new OpenTableRow($company, $email, $receipt, $source, $summary, $mail['client'], $mail['reply']);
    }

    /**
     * @param  array{client: ?string, reply: ?string, text: string, address: string, notes: string}  $mail
     */
    private function sentRow(string $client, string $inbound, string $clock, string $sent, array $mail): SentTableRow
    {
        $parts = explode(' / ', $client, 2);
        $company = trim($parts[0]);

        if ($company === '') {
            throw new DeskException('A sent row has no company.');
        }

        $email = $mail['address'];

        if ($email === '') {
            throw new DeskException("{$company} has no email address.");
        }
        $sentAt = $this->moment($sent);

        if (! $sentAt instanceof DateTimeImmutable) {
            throw new DeskException("{$company} has no sent time.");
        }

        $wroteFirst = str_starts_with(strtolower(trim($inbound)), 'none');
        $receipt = $this->moment($inbound) ?? $this->moment($clock);

        if (! $receipt instanceof DateTimeImmutable) {
            if (! $wroteFirst) {
                throw new DeskException("{$company} has no receipt time.");
            }

            $receipt = $sentAt;
        }

        $source = $this->logged($inbound.' '.$clock) || $wroteFirst
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

        return new SentTableRow($company, $email, $receipt, $source, $summary, $sentAt, $this->subject($mail['notes'], $summary), $mail['client'], $mail['reply']);
    }

    private function logged(string $text): bool
    {
        return str_contains($text, 'mail-client time not on the paste')
            || str_contains($text, 'brought into this log');
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

        if (in_array($label, ['inbound email', 'client email', 'his email', 'her email', 'his note', 'her note', 'yes'], true)) {
            return 'client';
        }

        if (in_array($label, ['reply email', 'reply', 'sent reply', 'cover', '£80 audit', 'php support note'], true)) {
            return 'reply';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function filed(string $baseDirectory, ?string $relative): ?FiledEmail
    {
        if ($baseDirectory === '' || $relative === null || $relative === '') {
            return null;
        }

        return FiledEmail::read($baseDirectory, $relative);
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
