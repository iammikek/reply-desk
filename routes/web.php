<?php

declare(strict_types=1);

use App\Http\Controllers\NoteAttachmentController;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Support\Facades\Route;

Route::middleware(Authenticate::class)
    ->get('/notes/{note}/attachment', NoteAttachmentController::class)
    ->name('reply-desk.notes.attachment');
