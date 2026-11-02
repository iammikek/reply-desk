<?php

namespace App\Desk\Backfill;

use App\Desk\DeskException;

final class MarkdownTable
{
    public function section(string $markdown, string $heading, bool $required = true): string
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
    public function table(string $section): array
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

            if ($cells[0] === '') {
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
    public function requireColumns(array $columns, string $which): void
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
    public function cell(array $columns, array $cells, string $name): string
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
    public function sentAtCell(array $columns, array $cells): string
    {
        if (array_key_exists('replied on', $columns)) {
            return trim($this->cell($columns, $cells, 'replied on'));
        }

        return trim($this->cell($columns, $cells, 'reply sent'));
    }
}
