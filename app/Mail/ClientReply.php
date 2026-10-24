<?php

namespace App\Mail;

use App\Desk\NoteFile;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ClientReply extends Mailable
{
    public function __construct(
        public string $subjectLine,
        public string $replyBody,
        public ?NoteFile $file = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                (string) config('mail.from.address'),
                (string) config('mail.from.name'),
            ),
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.client-reply',
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        if ($this->file === null) {
            return [];
        }

        return [
            Attachment::fromPath($this->file->path)->as($this->file->name),
        ];
    }
}
