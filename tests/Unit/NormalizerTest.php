<?php

use Odden\InboundMail\InboundMessage;
use Odden\InboundMail\Normalizer;

it('reads a Mailgun post, preferring the stripped text, and says nothing about authentication', function (): void {
    $mailgun = Normalizer::fromMailgun([
        'from' => 'Pat <pat@example.com>', 'sender' => 'pat@example.com', 'subject' => 'S', 'body-plain' => "Full\n> quoted", 'stripped-text' => 'Full',
        'message-headers' => json_encode([['Message-Id', '<m@x>'], ['X-Mailgun-Spf', 'Pass'], ['X-Mailgun-Dkim-Check-Result', 'Pass'], ['X-Mailgun-Sscore', '1.5']]),
    ]);

    expect($mailgun)->toBeInstanceOf(InboundMessage::class)
        ->and($mailgun->body)->toBe('Full')->and($mailgun->authenticated)->toBeNull()->and($mailgun->spamScore)->toBe(1.5)
        ->and($mailgun->messageId)->toBe('<m@x>')->and($mailgun->senderEmail())->toBe('pat@example.com')->and($mailgun->envelopeSender)->toBe('pat@example.com');
});

it('does not take SPF or DKIM results as the sender being who they say, whatever they say', function (): void {
    $failed = Normalizer::fromMailgun(['from' => 'a@b.co', 'body-plain' => 'x', 'message-headers' => json_encode([['X-Mailgun-Spf', 'Pass'], ['X-Mailgun-Dkim-Check-Result', 'Fail']])]);
    $silent = Normalizer::fromMailgun(['from' => 'a@b.co', 'body-plain' => 'x']);
    $sendgrid = Normalizer::fromSendGrid(['from' => 'a@b.co', 'text' => 'T', 'SPF' => 'pass', 'dkim' => '{@b.co : pass}']);
    $postmark = Normalizer::fromPostmark(['From' => 'a@b.co', 'TextBody' => 'T', 'Headers' => [['Name' => 'Received-SPF', 'Value' => 'Pass (sender SPF authorized)']]]);

    expect($failed->authenticated)->toBeNull()->and($silent->authenticated)->toBeNull()->and($sendgrid->authenticated)->toBeNull()->and($postmark->authenticated)->toBeNull();
});

it('falls back to the HTML body as plain text, without scripts or styles', function (): void {
    $html = Normalizer::fromMailgun(['from' => 'a@b.co', 'body-html' => '<p>Hello&nbsp;there</p><style>p{}</style><script>x()</script><p>Bye</p>']);

    expect($html->body)->toContain('Hello')->toContain('Bye')->not->toContain('x()')->not->toContain('p{}');
});

it('reads a Postmark post', function (): void {
    $postmark = Normalizer::fromPostmark(['From' => 'Pat <pat@example.com>', 'Subject' => 'P', 'StrippedTextReply' => 'Short', 'TextBody' => 'Long', 'MessageID' => 'pm-9', 'SpamScore' => 2.5,
        'Headers' => [['Name' => 'Received-SPF', 'Value' => 'Pass'], ['Name' => 'In-Reply-To', 'Value' => '<ref.x@y>']]]);

    expect($postmark->body)->toBe('Short')->and($postmark->subject)->toBe('P')->and($postmark->messageId)->toBe('pm-9')->and($postmark->spamScore)->toBe(2.5)
        ->and($postmark->header('in-reply-to'))->toBe('<ref.x@y>');
});

it('reads a SendGrid post, with its headers as one block and its envelope as JSON', function (): void {
    $sendgrid = Normalizer::fromSendGrid([
        'from' => 'a@b.co', 'text' => 'T', 'envelope' => json_encode(['from' => 'bounce@b.co', 'to' => ['x@y.z']]),
        'headers' => "Received: x\r\nIn-Reply-To: <a@b>\r\nReferences: <c@d>\r\n <e@f>",
    ]);

    expect($sendgrid->header('References'))->toBe('<c@d> <e@f>')->and($sendgrid->header('In-Reply-To'))->toBe('<a@b>')->and($sendgrid->envelopeSender)->toBe('bounce@b.co');
});

it('takes what a provider says outright about the From address: a DMARC result or its own flag', function (): void {
    expect(Normalizer::fromGeneric(['from' => 'a@b.co', 'text' => 'T', 'dmarc' => 'pass'])->authenticated)->toBeTrue()
        ->and(Normalizer::fromGeneric(['from' => 'a@b.co', 'text' => 'T', 'dmarc' => 'fail'])->authenticated)->toBeFalse()
        ->and(Normalizer::fromGeneric(['from' => 'a@b.co', 'text' => 'T', 'sender_authenticated' => true])->authenticated)->toBeTrue()
        ->and(Normalizer::fromGeneric(['from' => 'a@b.co', 'text' => 'T'])->authenticated)->toBeNull();
});

it('reads your own JSON, with its reply headers', function (): void {
    $message = Normalizer::fromGeneric(['from' => 'Pat <pat@example.com>', 'subject' => 'Hi', 'body' => 'Hello', 'in_reply_to' => '<a@b>', 'references' => '<c@d>']);

    expect($message->body)->toBe('Hello')->and($message->header('In-Reply-To'))->toBe('<a@b>')->and($message->header('References'))->toBe('<c@d>')
        ->and(Normalizer::fromGeneric(['from' => 'a@b.co', 'body' => 'x'])->subject)->toBe('No Subject');
});

it('recognises a Mailgun post by its fields', function (): void {
    expect(Normalizer::looksLikeMailgun(['body-plain' => 'x']))->toBeTrue()->and(Normalizer::looksLikeMailgun(['message-headers' => '[]']))->toBeTrue()
        ->and(Normalizer::looksLikeMailgun(['from' => 'a@b.co', 'text' => 'x']))->toBeFalse();
});

it('reads headers from a list, a map, a raw block or a list of name and value objects', function (): void {
    $shapes = [
        [['X-A', '1']],
        ['X-A' => '1'],
        "X-A: 1\r\nX-B: 2",
        json_encode([['X-A', '1']]),
        [['Name' => 'X-A', 'Value' => '1']],
    ];

    foreach ($shapes as $headers) {
        expect(Normalizer::fromGeneric(['from' => 'a@b.co', 'text' => 'x', 'headers' => $headers])->header('x-a'))->toBe('1');
    }
});
