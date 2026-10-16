<?php

use Odden\InboundMail\DeliveryReport;

it('reads the recipients a status notification says could not be delivered, and who the returned message was from', function (): void {
    $report = DeliveryReport::parse(
        "Reporting-MTA: dns; mx.example.com\r\n\r\nFinal-Recipient: rfc822; Gone@Example.org\r\nAction: failed\r\nStatus: 5.1.1\r\nDiagnostic-Code: smtp; 550 no\r\n such user\r\n\r\nFinal-Recipient: rfc822; later@example.org\r\nAction: delayed\r\nStatus: 4.4.1\r\n",
        "From: Sender <sender@example.net>\r\nSubject: Hello\r\n",
    );

    expect($report?->recipients)->toHaveCount(2)
        ->and($report?->recipients[0])->toBe(['recipient' => 'gone@example.org', 'action' => 'failed', 'status' => '5.1.1', 'diagnostic' => 'smtp; 550 no such user'])
        ->and($report?->originalFrom)->toBe('sender@example.net');
});

it('calls a permanent failure a hard bounce and a temporary one a soft bounce, and ignores everything else', function (): void {
    $entry = fn (string $action, string $status) => ['recipient' => 'a@b.co', 'action' => $action, 'status' => $status, 'diagnostic' => ''];

    expect(DeliveryReport::eventFor($entry('failed', '5.1.1')))->toBe('hard_bounce')
        ->and(DeliveryReport::eventFor($entry('failed', '4.2.2')))->toBe('soft_bounce')
        ->and(DeliveryReport::eventFor($entry('delayed', '4.4.1')))->toBeNull()
        ->and(DeliveryReport::eventFor($entry('delivered', '2.0.0')))->toBeNull()
        ->and(DeliveryReport::eventFor($entry('failed', '')))->toBeNull();
});

it('returns nothing for a report that names no valid recipient', function (): void {
    expect(DeliveryReport::parse("Reporting-MTA: dns; mx\r\n\r\nFinal-Recipient: rfc822; not an address\r\nAction: failed\r\n"))->toBeNull()
        ->and(DeliveryReport::parse(''))->toBeNull();
});

it('truncates a long diagnostic', function (): void {
    $report = DeliveryReport::parse("Final-Recipient: rfc822; a@b.co\r\nAction: failed\r\nStatus: 5.0.0\r\nDiagnostic-Code: ".str_repeat('x', 900)."\r\n");

    expect(mb_strlen($report?->recipients[0]['diagnostic'] ?? ''))->toBe(500);
});
