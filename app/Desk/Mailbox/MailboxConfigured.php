<?php

declare(strict_types=1);

namespace App\Desk\Mailbox;

final class MailboxConfigured
{
    /**
     * @param  array<string, mixed>  $config
     */
    public static function credentials(array $config): bool
    {
        return filled($config['imap_host'] ?? null)
            && filled($config['imap_username'] ?? null)
            && filled($config['imap_password'] ?? null)
            && filled($config['smtp_host'] ?? null)
            && filled($config['smtp_username'] ?? null)
            && filled($config['smtp_password'] ?? null)
            && filled($config['from_address'] ?? null);
    }
}
