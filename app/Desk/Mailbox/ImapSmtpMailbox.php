<?php

namespace App\Desk\Mailbox;

use App\Desk\DeskException;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
use Throwable;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;

final class ImapSmtpMailbox implements Mailbox
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private array $config) {}

    public function configured(): bool
    {
        return $this->imapReady() && $this->smtpReady();
    }

    public function fetchSince(?string $cursor, ?string $expectedUidValidity = null): InboxFetch
    {
        $client = $this->imapClient();
        $client->connect();
        $folder = $client->getFolder($this->config['inbox_folder'] ?: 'INBOX');
        $status = $folder->select();
        $uidValidity = isset($status['uidvalidity']) ? (string) $status['uidvalidity'] : null;

        if (
            $expectedUidValidity !== null
            && $uidValidity !== null
            && $expectedUidValidity !== $uidValidity
        ) {
            $cursor = null;
        }

        $query = $folder->messages()->all()->setFetchOrder('asc');

        $collection = $cursor !== null && $cursor !== '' && ctype_digit($cursor)
            ? $query->getByUidGreater((int) $cursor)
            : $query->get();

        $messages = [];

        /** @var Message $message */
        foreach ($collection as $message) {
            $uid = (string) $message->getUid();

            $date = null;
            $rawDate = (string) $message->getDate();

            if ($rawDate !== '') {
                try {
                    $date = new DateTimeImmutable($rawDate);
                } catch (Throwable) {
                    $date = null;
                }
            }

            $references = [];
            $rawReferences = (string) $message->getReferences();

            if ($rawReferences !== '') {
                preg_match_all('/<[^>]+>/', $rawReferences, $matches);
                $references = $matches[0] ?? [];
            }

            $messages[] = new IncomingMessage(
                messageId: trim((string) $message->getMessageId()),
                fromEmail: strtolower(trim((string) $message->getFrom()->first()?->mail)),
                subject: (string) $message->getSubject(),
                textBody: (string) $message->getTextBody(),
                htmlBody: (string) $message->getHTMLBody(),
                date: $date,
                inReplyTo: trim((string) $message->getInReplyTo()),
                references: $references,
                cursor: $uid,
            );
        }

        $client->disconnect();

        return new InboxFetch($messages, $uidValidity);
    }

    public function upsertDraft(string $identity, string $to, string $subject, string $body): string
    {
        $client = $this->imapClient();
        $client->connect();
        $folder = $client->getFolder($this->config['drafts_folder'] ?: 'Drafts');

        if ($identity !== '') {
            try {
                $existing = $folder->messages()->getMessageByUid((int) $identity);
                $existing?->delete(expunge: true);
            } catch (Throwable) {
                // Replace when possible; a missing draft is recreated.
            }
        }

        $uid = $this->appendRaw($folder, $this->buildMime($to, $subject, $body)->toString());
        $client->disconnect();

        return $uid;
    }

    public function deleteDraft(?string $identity): void
    {
        if ($identity === null || $identity === '' || ! ctype_digit($identity)) {
            return;
        }

        try {
            $client = $this->imapClient();
            $client->connect();
            $folder = $client->getFolder($this->config['drafts_folder'] ?: 'Drafts');
            $message = $folder->messages()->getMessageByUid((int) $identity);
            $message?->delete(expunge: true);
            $client->disconnect();
        } catch (Throwable) {
            //
        }
    }

    public function send(string $to, string $subject, string $body, ?string $attachmentPath = null, ?string $attachmentName = null): string
    {
        $email = $this->buildMime($to, $subject, $body);

        if ($attachmentPath !== null && is_file($attachmentPath)) {
            $email->attachFromPath($attachmentPath, $attachmentName);
        }

        try {
            $mailer = new Mailer(Transport::fromDsn($this->smtpDsn()));
            $sent = $mailer->send($email);
        } catch (Throwable $exception) {
            Log::warning('Mailbox SMTP send failed.', [
                'error' => $exception->getMessage(),
            ]);

            throw new DeskException('The mail server refused the message.');
        }

        $messageId = $sent?->getMessageId() ?: $email->getHeaders()->getHeaderBody('Message-ID');

        if (! is_string($messageId) || trim($messageId) === '') {
            $messageId = '<'.bin2hex(random_bytes(12)).'@reply-desk>';
        }

        $messageId = trim($messageId);

        try {
            $raw = ($sent?->getOriginalMessage() ?? $email)->toString();
            $this->appendSentCopy($raw);
        } catch (Throwable $exception) {
            Log::warning('Mailbox Sent folder append failed.', [
                'error' => $exception->getMessage(),
                'message_id' => $messageId,
            ]);
        }

        return $messageId;
    }

    private function appendSentCopy(string $raw): void
    {
        $client = $this->imapClient();
        $client->connect();
        $folder = $client->getFolder($this->config['sent_folder'] ?: 'Sent');
        $this->appendRaw($folder, $raw, ['\\Seen']);
        $client->disconnect();
    }

    /**
     * @param  list<string>|null  $flags
     */
    private function appendRaw(Folder $folder, string $raw, ?array $flags = null): string
    {
        $status = $folder->select();
        $uidNext = isset($status['uidnext']) ? (int) $status['uidnext'] : 0;

        if ($uidNext < 1) {
            throw new DeskException('The mailbox folder did not accept the message.');
        }

        $response = $folder->appendMessage($raw, $flags);
        $accepted = false;

        foreach ($response as $line) {
            if (is_string($line) && str_starts_with($line, 'OK')) {
                $accepted = true;
                break;
            }
        }

        if (! $accepted) {
            throw new DeskException('The mailbox folder did not accept the message.');
        }

        return (string) $folder->messages()->getMessageByUid($uidNext)->getUid();
    }

    private function buildMime(string $to, string $subject, string $body): Email
    {
        $from = (string) ($this->config['from_address'] ?? '');

        return (new Email)
            ->from($from)
            ->to($to)
            ->subject($subject)
            ->text($body)
            ->date(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    private function imapClient(): mixed
    {
        $manager = new ClientManager;

        return $manager->make([
            'host' => $this->config['imap_host'],
            'port' => $this->config['imap_port'],
            'encryption' => $this->config['imap_encryption'] ?: false,
            'validate_cert' => true,
            'username' => $this->config['imap_username'],
            'password' => $this->config['imap_password'],
            'protocol' => 'imap',
        ]);
    }

    private function smtpDsn(): string
    {
        $user = rawurlencode((string) $this->config['smtp_username']);
        $pass = rawurlencode((string) $this->config['smtp_password']);
        $host = (string) $this->config['smtp_host'];
        $port = (int) $this->config['smtp_port'];
        $encryption = (string) ($this->config['smtp_encryption'] ?? '');

        $scheme = match ($encryption) {
            'ssl' => 'smtps',
            default => 'smtp',
        };

        $dsn = "{$scheme}://{$user}:{$pass}@{$host}:{$port}";

        if ($encryption === 'tls') {
            $dsn .= '?encryption=tls';
        }

        return $dsn;
    }

    private function imapReady(): bool
    {
        return filled($this->config['imap_host'] ?? null)
            && filled($this->config['imap_username'] ?? null)
            && filled($this->config['imap_password'] ?? null)
            && filled($this->config['from_address'] ?? null);
    }

    private function smtpReady(): bool
    {
        return filled($this->config['smtp_host'] ?? null)
            && filled($this->config['smtp_username'] ?? null)
            && filled($this->config['smtp_password'] ?? null);
    }
}
