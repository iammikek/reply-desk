<?php

namespace App\Desk\Notes;

use App\Desk\DeskException;
use Illuminate\Support\Facades\Log;

final class HubSpotFiles
{
    /**
     * File names looked up while reading notes. One note list should not ask HubSpot twice for the same file.
     *
     * @var array<string, string>
     */
    private array $names = [];

    public function __construct(private HubSpotClient $client) {}

    public function attachment(StoredNote $note): ?NoteAttachment
    {
        if ($note->attachmentId === null) {
            return null;
        }

        $signed = $this->client->send('GET', '/files/v3/files/'.rawurlencode($note->attachmentId).'/signed-url');
        $url = (string) ($signed['url'] ?? '');

        if ($url === '') {
            throw new DeskException('HubSpot did not return a file link.');
        }

        return new NoteAttachment($note->attachmentName ?? 'Attachment', url: $url);
    }

    public function firstId(string $ids): ?string
    {
        $id = trim(explode(';', $ids)[0]);

        if ($id === '' || preg_match('/^[A-Za-z0-9_-]+$/', $id) !== 1) {
            return null;
        }

        return $id;
    }

    public function name(string $id): string
    {
        if (isset($this->names[$id])) {
            return $this->names[$id];
        }

        try {
            $file = $this->client->send('GET', '/files/v3/files/'.rawurlencode($id));
        } catch (DeskException $exception) {
            Log::debug('HubSpot file name could not be read.', [
                'file_id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return $this->names[$id] = 'Attachment';
        }

        $name = trim((string) ($file['name'] ?? ''));
        $extension = trim((string) ($file['extension'] ?? ''), '. ');

        if ($name === '') {
            return $this->names[$id] = 'Attachment';
        }

        if ($extension !== '' && ! str_ends_with(strtolower($name), '.'.strtolower($extension))) {
            $name .= '.'.$extension;
        }

        return $this->names[$id] = $name;
    }
}
