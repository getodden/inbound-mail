<?php

namespace Odden\InboundMail;

use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * The one address a From header names, read the way a mail client reads it.
 *
 * A From value is whatever the sender typed: a display name can hold "<victim@example.com>", a comment can hold an address, and
 * a header can list several. Picking the first thing in angle brackets (or the last) lets the sender choose which address your
 * application believes wrote the mail. Here the header is parsed with a real address parser, and anything that does not name
 * exactly one valid address is refused.
 */
final readonly class SenderAddress
{
    private function __construct(public ?string $email, public string $name) {}

    public static function parse(string $from): self
    {
        $value = trim(str_replace(["\r", "\n"], ' ', $from));

        if ($value === '' || mb_strlen($value) > 2000) {
            return new self(null, '');
        }

        $header = (new MailMimeParser)->parse("From: {$value}\r\n\r\n", true)->getHeader('From');
        $addresses = $header instanceof AddressHeader ? array_values($header->getAddresses()) : [];

        if (count($addresses) !== 1) {
            return new self(null, '');
        }

        $email = mb_strtolower(trim((string) $addresses[0]->getEmail()));
        // PHP accepts a quoted local part ("<a>"@example.com), which carries quotes and brackets into the address; a sender that
        // wants to be read as another address would use one. Mail clients almost never send them, so they are refused.
        $valid = filter_var($email, FILTER_VALIDATE_EMAIL) !== false && preg_match('/[\s<>"\\\\\x00-\x1F\x7F]/', $email) !== 1;

        return new self($valid ? $email : null, $valid ? self::cleanName((string) $addresses[0]->getName()) : '');
    }

    /**
     * The address in the form a downstream parser reads back to the same address: a name that cannot hold brackets or quotes.
     */
    public function canonical(): string
    {
        if ($this->email === null) {
            return '';
        }

        return $this->name === '' ? $this->email : '"'.$this->name.'" <'.$this->email.'>';
    }

    private static function cleanName(string $name): string
    {
        $name = trim((string) preg_replace('/[<>"\\\\\x00-\x1F\x7F]+/u', ' ', $name));

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $name)), 0, 200);
    }
}
