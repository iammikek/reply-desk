<?php

namespace App\Http\Controllers;

use App\Desk\Notes\NoteStore;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class NoteAttachmentController
{
    public function __invoke(string $note): Response
    {
        $file = app(NoteStore::class)->attachmentFor($note);

        if ($file === null) {
            abort(404);
        }

        if ($file->url !== null) {
            return redirect()->away($file->url);
        }

        if ($file->path === null || ! Storage::disk('local')->exists($file->path)) {
            abort(404);
        }

        return Storage::disk('local')->response($file->path, $file->name);
    }
}
