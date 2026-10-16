<?php

use Odden\InboundMail\Normalizer;

function sesRaw(string $headers, string $body = "Hello\r\n"): string
{
    return "From: Pat <pat@example.com>\r\nTo: inbox@inbound.example\r\nSubject: Hi\r\nMessage-ID: <m-1@example.com>\r\nMIME-Version: 1.0\r\n{$headers}\r\n\r\n{$body}";
}

it('reads a multipart message with an HTML part and an encoded subject', function (): void {
    $raw = "From: =?UTF-8?Q?Zo=C3=AB_Buyer?= <zoe@example.com>\r\nTo: inbox@inbound.example\r\nSubject: =?UTF-8?Q?Caf=C3=A9_order?=\r\nMessage-ID: <mp-1@example.com>\r\nMIME-Version: 1.0\r\n"
        ."Content-Type: multipart/alternative; boundary=\"b1\"\r\n\r\n--b1\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n<p>Hello&nbsp;there</p><script>x()</script><p>Bye</p>\r\n--b1--\r\n";

    $message = Normalizer::fromSes([
        'mail' => ['source' => 'zoe@example.com', 'commonHeaders' => ['messageId' => '<mp-1@example.com>']],
        'receipt' => ['spfVerdict' => ['status' => 'PASS'], 'dkimVerdict' => ['status' => 'PASS'], 'dmarcVerdict' => ['status' => 'FAIL'], 'spamVerdict' => ['status' => 'PASS']],
    ], $raw);

    expect($message->subject)->toBe('Café order')
        ->and($message->senderEmail())->toBe('zoe@example.com')
        ->and($message->body)->toContain('Hello')->toContain('Bye')->not->toContain('x()')
        ->and($message->messageId)->toBe('<mp-1@example.com>')
        ->and($message->authenticated)->toBeFalse()->and($message->spamScore)->toBe(0.0);
});

it('vouches for a sender only on a DMARC pass, never on SPF or DKIM alone', function (): void {
    $verdicts = fn (string $dmarc) => ['mail' => [], 'receipt' => ['spfVerdict' => ['status' => 'PASS'], 'dkimVerdict' => ['status' => 'PASS'], 'dmarcVerdict' => ['status' => $dmarc]]];

    expect(Normalizer::fromSes($verdicts('PASS'), sesRaw('Content-Type: text/plain'))->authenticated)->toBeTrue()
        ->and(Normalizer::fromSes($verdicts('FAIL'), sesRaw('Content-Type: text/plain'))->authenticated)->toBeFalse()
        ->and(Normalizer::fromSes($verdicts('GRAY'), sesRaw('Content-Type: text/plain'))->authenticated)->toBeFalse()
        ->and(Normalizer::fromSes(['mail' => [], 'receipt' => []], sesRaw('Content-Type: text/plain'))->authenticated)->toBeNull();
});

it('turns the spam verdict into a score: at the configured maximum when it fails', function (): void {
    $spam = fn (string $status) => Normalizer::fromSes(['mail' => [], 'receipt' => ['spamVerdict' => ['status' => $status]]], sesRaw('Content-Type: text/plain'))->spamScore;

    expect($spam('PASS'))->toBe(0.0)->and($spam('FAIL'))->toBe(10.0)->and($spam('GRAY'))->toBeNull();

    config(['inbound-mail.spam_score_max' => 12.0]);

    expect($spam('FAIL'))->toBe(12.0);
});

it('cuts the quoted thread from a plain-text reply, and keeps the text whole when nothing else would be left', function (): void {
    $reply = Normalizer::fromSes(['mail' => [], 'receipt' => []], sesRaw('Content-Type: text/plain', "Thanks, any news?\r\n\r\nOn Sun, Oct 11, 2026 at 8:41 AM Support <support@example.com> wrote:\r\n> An update.\r\n"));
    $only = Normalizer::fromSes(['mail' => [], 'receipt' => []], sesRaw('Content-Type: text/plain', "> only a quote\r\n"));

    expect($reply->body)->toBe('Thanks, any news?')->and(trim($only->body))->toBe('> only a quote');
});

it('finds the references your own emails carry in the quoted text, with the patterns you configure', function (): void {
    $quoted = "Thanks!\r\n\r\nOn Sun, Oct 11 Support wrote:\r\n> View it: https://example.com/support/tickets/".str_repeat('a1', 20)."\r\n> or https://example.com/portal/".str_repeat('b2', 20)."\r\n";

    $none = Normalizer::fromSes(['mail' => [], 'receipt' => []], sesRaw('Content-Type: text/plain', $quoted));
    config(['inbound-mail.thread_token_patterns' => ['~(?:support/tickets/|portal/)([a-z0-9]{40})(?![a-z0-9])~i', 'not a pattern']]);
    $found = Normalizer::fromSes(['mail' => [], 'receipt' => []], sesRaw('Content-Type: text/plain', $quoted));

    expect($none->threadTokens)->toBe([])
        ->and($found->threadTokens)->toBe([str_repeat('a1', 20), str_repeat('b2', 20)])
        ->and($found->body)->toBe('Thanks!'); // the quoted thread is cut from the body, the references were found before that
});

it('reads a delivery status notification into its report', function (): void {
    $raw = "From: MAILER-DAEMON@example.com\r\nTo: inbox@inbound.example\r\nSubject: Undelivered\r\nMessage-ID: <dsn@example.com>\r\nMIME-Version: 1.0\r\n"
        ."Content-Type: multipart/report; report-type=delivery-status; boundary=\"r1\"\r\n\r\n--r1\r\nContent-Type: text/plain\r\n\r\nCould not deliver.\r\n"
        ."--r1\r\nContent-Type: message/delivery-status\r\n\r\nReporting-MTA: dns; mx.example.com\r\n\r\nFinal-Recipient: rfc822; gone@example.org\r\nAction: failed\r\nStatus: 5.1.1\r\nDiagnostic-Code: smtp; 550 no such user\r\n"
        ."--r1\r\nContent-Type: text/rfc822-headers\r\n\r\nFrom: Sender <sender@example.net>\r\nSubject: Hello\r\n--r1--\r\n";

    $message = Normalizer::fromSes(['mail' => ['source' => ''], 'receipt' => []], $raw);

    expect($message->report?->recipients)->toBe([['recipient' => 'gone@example.org', 'action' => 'failed', 'status' => '5.1.1', 'diagnostic' => 'smtp; 550 no such user']])
        ->and($message->report?->originalFrom)->toBe('sender@example.net');
});

it('falls back to the common headers when the raw message has no From of its own', function (): void {
    $message = Normalizer::fromSes(['mail' => ['commonHeaders' => ['from' => ['Pat <pat@example.com>'], 'messageId' => '<c1@x>']], 'receipt' => []], "Subject: Hi\r\n\r\nBody\r\n");

    expect($message->senderEmail())->toBe('pat@example.com')->and($message->messageId)->toBe('<c1@x>');
});
