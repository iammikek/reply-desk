<?php

declare(strict_types=1);

namespace App\Clock;

enum ReceiptSource: string
{
    case MailClient = 'mail client';
    case Logged = 'logged';
    case WhatsApp = 'whatsapp';

    public function label(): string
    {
        return match ($this) {
            self::MailClient => 'Mail client',
            self::Logged => 'Logged',
            self::WhatsApp => 'WhatsApp',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
