<?php

namespace App\Clock;

enum ReceiptSource: string
{
    case MailClient = 'mail client';
    case Logged = 'logged';
}
