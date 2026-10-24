<?php

namespace App\Desk\Notes;

final readonly class Party
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email = '',
    ) {}
}
