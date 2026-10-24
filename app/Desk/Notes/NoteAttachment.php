<?php

namespace App\Desk\Notes;

final class NoteAttachment
{
    public function __construct(
        public string $name,
        public ?string $path = null,
        public ?string $url = null,
    ) {}
}
