<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Desk\DeskException;
use App\Desk\Notes\NoteStore;

class DirectoryCommand extends ReplyDeskCommand
{
    protected $signature = 'reply:directory';

    protected $description = 'List companies and the contacts on each company';

    public function handle(NoteStore $notes): int
    {
        try {
            return $this->json($notes->directory());
        } catch (DeskException $exception) {
            return $this->failure($exception);
        }
    }
}
