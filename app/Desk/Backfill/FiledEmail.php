<?php

namespace App\Desk\Backfill;

final readonly class FiledEmail
{
    public function __construct(
        public string $subject,
        public string $body,
    ) {}

    public static function read(string $baseDirectory, string $relativePath): ?self
    {
        $relativePath = trim($relativePath);

        if ($relativePath === '' || str_contains($relativePath, '://') || str_contains($relativePath, "\0")) {
            return null;
        }

        $base = realpath($baseDirectory);

        if ($base === false) {
            return null;
        }

        $path = realpath($base.DIRECTORY_SEPARATOR.$relativePath);

        if ($path === false || ! is_file($path) || ! str_starts_with($path, $base.DIRECTORY_SEPARATOR)) {
            return null;
        }

        $markdown = file_get_contents($path);

        if ($markdown === false || trim($markdown) === '') {
            return null;
        }

        $subject = '';

        if (preg_match('/^\*\*Subject:\*\*\s*(.+)$/m', $markdown, $matches) === 1) {
            $subject = trim($matches[1]);
        }

        if (preg_match('/^## Body(?: \(verbatim\))?\s*\R(.*)\z/ms', $markdown, $matches) === 1) {
            $body = trim($matches[1]);
        } else {
            $body = trim($markdown);
        }

        if ($body === '') {
            return null;
        }

        return new self($subject, $body);
    }
}
