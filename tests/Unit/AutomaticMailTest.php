<?php

use Odden\InboundMail\AutomaticMail;
use Odden\InboundMail\InboundMessage;

function mailFrom(string $from = 'pat@example.com', array $headers = [], ?string $envelope = 'pat@example.com', string $subject = 'Hi'): InboundMessage
{
    return new InboundMessage($from, $subject, 'Body', $headers, envelopeSender: $envelope);
}

it('says why a machine sent a message', function (InboundMessage $message, ?string $reason): void {
    expect(AutomaticMail::reason($message, ['support@ours.example']))->toBe($reason);
})->with([
    'a person' => [fn () => mailFrom(), null],
    'an auto reply' => [fn () => mailFrom(headers: [['Auto-Submitted', 'auto-replied']]), 'auto-submitted'],
    'Auto-Submitted: no' => [fn () => mailFrom(headers: [['Auto-Submitted', 'no']]), null],
    'bulk' => [fn () => mailFrom(headers: [['Precedence', 'bulk']]), 'bulk'],
    'list precedence' => [fn () => mailFrom(headers: [['Precedence', 'list']]), 'bulk'],
    'outlook auto' => [fn () => mailFrom(headers: [['X-Auto-Response-Suppress', 'All']]), 'auto-reply'],
    'an autoreply header' => [fn () => mailFrom(headers: [['X-Autoreply', 'yes']]), 'auto-reply'],
    'a mailing list' => [fn () => mailFrom(headers: [['List-Id', '<news.example.com>']]), 'mailing list'],
    'an empty envelope sender' => [fn () => mailFrom(envelope: ''), 'bounce'],
    'a null return path' => [fn () => mailFrom(envelope: '<>'), 'bounce'],
    'mailer daemon' => [fn () => mailFrom('MAILER-DAEMON@example.com'), 'bounce'],
    'postmaster' => [fn () => mailFrom('Postmaster <postmaster@example.com>'), 'bounce'],
    'a delivery report' => [fn () => mailFrom(headers: [['Content-Type', 'multipart/report; report-type=delivery-status']]), 'bounce'],
    'one of our own addresses' => [fn () => mailFrom('Support <Support@Ours.example>'), 'own address'],
]);

it('puts a bounce before the other reasons', function (): void {
    expect(AutomaticMail::reason(mailFrom('MAILER-DAEMON@example.com', [['Auto-Submitted', 'auto-replied']])))->toBe('bounce');
});

it('does not know an envelope sender it was not given', function (): void {
    expect(AutomaticMail::reason(mailFrom(envelope: null)))->toBeNull();
});

it('reads a subject that starts like an out-of-office reply, after any Re: or Fwd:', function (string $subject, bool $says): void {
    expect(AutomaticMail::saysOutOfOffice(mailFrom(subject: $subject)))->toBe($says);
})->with([
    ['Out of office until Monday', true],
    ['Automatic reply: your order', true],
    ['Re: Re: Auto-reply', true],
    ['FW: Away from my desk', true],
    ['Our office is open', false],
    ['A question about out of office hours', false],
]);
