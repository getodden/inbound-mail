<?php

use Odden\InboundMail\SenderAddress;

it('reads the address and the name the way a mail client does', function (string $from, string $email, string $name): void {
    $sender = SenderAddress::parse($from);

    expect($sender->email)->toBe($email)->and($sender->name)->toBe($name);
})->with([
    'plain' => ['pat@example.com', 'pat@example.com', ''],
    'with a name' => ['Pat Customer <Pat@Example.com>', 'pat@example.com', 'Pat Customer'],
    'quoted name' => ['"Customer, Pat" <pat@example.com>', 'pat@example.com', 'Customer, Pat'],
    'an encoded name' => ['=?UTF-8?Q?Zo=C3=AB?= <zoe@example.com>', 'zoe@example.com', 'Zoë'],
]);

it('refuses a From that does not name exactly one valid address', function (string $from): void {
    $sender = SenderAddress::parse($from);

    expect($sender->email)->toBeNull()->and($sender->canonical())->toBe('');
})->with([
    'empty' => [''],
    'two addresses' => ['a@example.com, b@example.com'],
    'not an address' => ['Pat Customer'],
    'no domain' => ['pat@'],
    'a space in the address' => ['"pat smith"@example.com'],
    'too long' => [str_repeat('a', 2001).'@example.com'],
]);

it('does not let a display name choose the address: the address in the angle brackets is the sender, whatever the name holds', function (): void {
    $forged = SenderAddress::parse('"victim@example.com <victim@example.com>" <attacker@evil.example>');
    $comment = SenderAddress::parse('attacker@evil.example (victim@example.com)');

    expect($forged->email)->toBe('attacker@evil.example')->and($comment->email)->toBe('attacker@evil.example');
});

it('rebuilds a header that reads back as the same address, with a name that cannot hold brackets or quotes', function (): void {
    $sender = SenderAddress::parse('"Pat <victim@example.com>" <attacker@evil.example>');

    expect($sender->name)->not->toMatch('/[<>"\\\\]/')
        ->and(SenderAddress::parse($sender->canonical())->email)->toBe('attacker@evil.example')
        ->and(preg_match('/(.*)<(.+@.+?)>/', $sender->canonical(), $m) === 1 && $m[2] === 'attacker@evil.example')->toBeTrue();
});

it('holds its invariants for hostile and random input', function (): void {
    mt_srand(20261016);
    $alphabet = ['<', '>', '"', '\\', ' ', '@', ',', '(', ')', ';', ':', "\r", "\n", "\0", 'a', 'b', '.', 'é', '=', '?', 'x@y.z', '<a@b.co>', '=?UTF-8?Q?a?='];
    $inputs = ['"<a@b.co>" <c@d.co>', 'a@b.co <c@d.co>', "a@b.co\r\nBcc: c@d.co", '<<a@b.co>>', '@@@', str_repeat('<', 500), "\0a@b.co"];

    for ($i = 0; $i < 400; $i++) {
        $input = '';
        for ($n = mt_rand(0, 14); $n > 0; $n--) {
            $input .= $alphabet[mt_rand(0, count($alphabet) - 1)];
        }
        $inputs[] = $input;
    }

    foreach ($inputs as $input) {
        $sender = SenderAddress::parse($input);

        if ($sender->email === null) {
            expect($sender->canonical())->toBe('');

            continue;
        }

        expect(filter_var($sender->email, FILTER_VALIDATE_EMAIL))->not->toBeFalse()
            ->and($sender->email)->toBe(mb_strtolower($sender->email))
            ->and(preg_match('/[\s<>"\\\\\x00-\x1F\x7F]/', $sender->email))->toBe(0)
            ->and(preg_match('/[<>"\\\\\x00-\x1F\x7F]/', $sender->name))->toBe(0)
            ->and(mb_strlen($sender->name))->toBeLessThanOrEqual(200)
            ->and(SenderAddress::parse($sender->canonical())->email)->toBe($sender->email);
    }
});
