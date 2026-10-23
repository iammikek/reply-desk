<?php

namespace App\Console\Commands;

use App\Desk\DeskException;
use App\Desk\LogInbound;
use App\Desk\NoteStore;
use App\Desk\OpenTable;

class BackfillOpenCommand extends ReplyDeskCommand
{
    protected $signature = 'reply:backfill {path} {--write : Write the inbound notes to portal 145255358}';

    protected $description = 'Print the Open table as inbound clock notes, and write them only for the live portal';

    public function handle(OpenTable $openTable, LogInbound $logInbound, NoteStore $notes): int
    {
        try {
            $markdown = file_get_contents((string) $this->argument('path'));

            if ($markdown === false) {
                throw new DeskException('That Open table could not be read.');
            }

            $rows = $openTable->rows($markdown);

            if (! $this->option('write')) {
                foreach ($openTable->preview($markdown) as $line) {
                    $this->line($line);
                }

                return 0;
            }

            if (config('reply-desk.store') !== 'hubspot' || (string) config('reply-desk.portal_id') !== '145255358') {
                throw new DeskException('Backfill writes only to portal 145255358.');
            }

            $notes->assertPortal();

            foreach ($rows as $row) {
                $id = $logInbound($row->company, $row->email, $row->receipt, $row->receiptSource, $row->summary);
                $this->line("Wrote {$id} for {$row->company}");
            }

            return 0;
        } catch (DeskException $exception) {
            return $this->failure($exception);
        }
    }
}
