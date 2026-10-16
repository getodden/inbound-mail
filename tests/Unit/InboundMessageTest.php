<?php

use Odden\InboundMail\InboundMessage;

it('names exactly one sender, or says there is none', function (): void {
    expect((new InboundMessage('Pat <pat@example.com>', 'S', 'B'))->hasSender())->toBeTrue()
        ->and((new InboundMessage('Pat <pat@example.com>', 'S', 'B'))->senderEmail())->toBe('pat@example.com')
        ->and((new InboundMessage('a@b.co, c@d.co', 'S', 'B'))->hasSender())->toBeFalse()
        ->and((new InboundMessage('a@b.co, c@d.co', 'S', 'B'))->senderEmail())->toBe('');
});

it('is verified only when it is vouched for', function (): void {
    expect((new InboundMessage('a@b.co', 'S', 'B', authenticated: true))->isVerified())->toBeTrue()
        ->and((new InboundMessage('a@b.co', 'S', 'B', authenticated: false))->isVerified())->toBeFalse()
        ->and((new InboundMessage('a@b.co', 'S', 'B'))->isVerified())->toBeFalse();
});

it('finds a header by name, ignoring case and the first one wins', function (): void {
    $message = new InboundMessage('a@b.co', 'S', 'B', [['X-One', ' 1 '], ['x-one', '2'], ['X-Two', '3']]);

    expect($message->header('X-ONE'))->toBe('1')->and($message->header('x-two'))->toBe('3')->and($message->header('X-Missing'))->toBeNull()
        ->and($message->hasHeader('x-two'))->toBeTrue()->and($message->hasHeader('X-Missing'))->toBeFalse();
});
