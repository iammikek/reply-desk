<?php

namespace App\Desk\Mailbox;

final class ExtractLatestMessage
{
    public function __invoke(string $textBody, string $htmlBody = ''): string
    {
        $text = trim($textBody);

        if ($text === '' && trim($htmlBody) !== '') {
            $text = $this->htmlToText($htmlBody);
        }

        if ($text === '') {
            return '';
        }

        $lines = preg_split("/\r\n|\n|\r/", $text) ?: [];
        $kept = [];

        foreach ($lines as $index => $line) {
            if ($this->isQuoteBoundary($line, $lines, $index)) {
                break;
            }

            if (str_starts_with(ltrim($line), '>')) {
                break;
            }

            $kept[] = $line;
        }

        return trim(implode("\n", $kept));
    }

    /**
     * @param  list<string>  $lines
     */
    private function isQuoteBoundary(string $line, array $lines, int $index): bool
    {
        $trimmed = trim($line);

        if ($trimmed === '') {
            return false;
        }

        if (preg_match('/^On .+ wrote:\s*$/i', $trimmed) === 1) {
            return true;
        }

        if (preg_match('/^-{2,}\s*Original Message\s*-{2,}$/i', $trimmed) === 1) {
            return true;
        }

        if (preg_match('/^_{5,}$/', $trimmed) === 1) {
            return true;
        }

        if (preg_match('/^From:\s.+/i', $trimmed) === 1) {
            $next = trim($lines[$index + 1] ?? '');
            if (preg_match('/^(Sent|To|Subject):\s/i', $next) === 1) {
                return true;
            }
        }

        return false;
    }

    private function htmlToText(string $html): string
    {
        $html = preg_replace('/<(br|\/p|\/div)\s*\/?>/i', "\n", $html) ?? $html;
        $html = preg_replace('/<blockquote\b[^>]*>.*?<\/blockquote>/is', '', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/[ \t]+\n/", "\n", $text) ?? $text);
    }
}
