<?php

declare(strict_types=1);

namespace App\Mail\Transport;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\MessageConverter;

final class PhpMailTransport extends AbstractTransport
{
    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $envelope = $message->getEnvelope();

        $to = implode(',', array_map(
            fn ($address): string => $address->getAddress(),
            $envelope->getRecipients(),
        ));

        if ($to === '') {
            throw new TransportException('PHP mail() needs at least one recipient.');
        }

        $headers = $email->getPreparedHeaders();
        $headers->remove('Subject');
        $headers->remove('To');

        $body = $email->getHtmlBody() ?? $email->getTextBody() ?? '';

        if (! is_string($body)) {
            $body = (string) $body;
        }

        $subject = $email->getSubject() ?? '';
        $headerString = $headers->toString();

        $sent = @mail($to, $subject, $body, $headerString, '-f'.$envelope->getSender()->getAddress());

        if ($sent !== true) {
            throw new TransportException('PHP mail() could not send the message.');
        }
    }

    public function __toString(): string
    {
        return 'php';
    }
}
