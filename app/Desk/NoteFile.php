<?php

namespace App\Desk;

use Illuminate\Support\Facades\Storage;

final class NoteFile
{
    public function __construct(
        public string $path,
        public string $name,
    ) {}

    public static function fromForm(mixed $stored, mixed $name): ?self
    {
        $path = is_array($stored) ? ($stored[array_key_first($stored)] ?? null) : $stored;

        if (! is_string($path) || $path === '') {
            return null;
        }

        $absolute = Storage::disk('local')->path($path);
        $filename = is_string($name) && $name !== '' ? $name : basename($path);

        return new self($absolute, $filename);
    }
}
