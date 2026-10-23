<?php

namespace App\Desk;

use App\Clock\ClockNote;
use App\Clock\ReceiptSource;
use App\Clock\ReplyClock;
use DateTimeImmutable;
use DateTimeZone;

final class OpenTable
{
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
     * @return list<string>
     */
    public function preview(string $markdown): array
    {
        $lines = [];

        foreach ($this->rows($markdown) as $row) {
            $lines[] = $row->company;
            $lines[] = $row->email;
            $lines[] = ClockNote::inbound($row->receipt, $row->receiptSource, $row->summary);
            $lines[] = '';
        }

        $lines[] = 'Dry run. Nothing was written.';

        return $lines;
    }

    private function section(string $markdown, string $heading): string
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
            throw new DeskException('No Open section in that file.');
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

        if (preg_match('/^(\d{1,2}) ([A-Za-z]{3}) (\d{4}) (\d{2}):(\d{2})/', trim($inbound), $stamp) !== 1) {
            throw new DeskException("{$company} has no receipt time.");
        }

        $months = [
            'Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4,
            'May' => 5, 'Jun' => 6, 'Jul' => 7, 'Aug' => 8,
            'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12,
        ];
        $month = $months[$stamp[2]] ?? null;

        if ($month === null) {
            throw new DeskException("{$company} has no receipt time.");
        }

        $receipt = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i',
            sprintf('%04d-%02d-%02d %s:%s', $stamp[3], $month, $stamp[1], $stamp[4], $stamp[5]),
            new DateTimeZone(ReplyClock::ZONE),
        );

        if ($receipt === false) {
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

        return new OpenTableRow($company, $email, $receipt, $source, $summary);
    }
}
