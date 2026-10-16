# Odden Inbound Mail

Receive email in Laravel from Amazon SES, Mailgun, Postmark and SendGrid as one hardened message object.

An inbound email is attacker-controlled input: the From header is whatever the sender typed, a display name can hold someone else's address, "SPF pass" says nothing about who wrote the message, and half of what arrives is a bounce, an auto-reply or a mailing list. This package reads what each provider posts into one `InboundMessage` and answers those questions the cautious way, so your helpdesk, CRM or "reply by email" feature does not have to.

Status: **0.1, in development.** The message, the sender parser, delivery reports, the provider normalizers and automatic-mail detection. A verified, deduplicated Amazon SES receiving endpoint (SNS, S3) is planned for 0.2. It is the inbound code of [Odden Cloud](https://odden.io), extracted.

## What it does

- **One message for every provider.** `Normalizer::fromMailgun()`, `fromPostmark()`, `fromSendGrid()`, `fromGeneric()` (your own JSON) and `fromSes()` (an SES receipt notification plus the raw message from S3) all return the same `InboundMessage`: sender, subject, plain-text body (the quoted thread cut away), headers, message id, envelope sender, spam score, authentication, thread tokens and delivery report.
- **A sender parsed like a mail client parses it.** `SenderAddress` uses a real address parser. A display name that contains `<victim@example.com>`, a comment that holds an address, or a header that lists several addresses cannot choose who your application believes wrote the mail: anything that is not exactly one valid address is refused (`hasSender()` is false).
- **An honest `authenticated` flag.** It is true only when the provider vouches for the *From address*: an SES DMARC pass, or a provider's own statement. SPF and DKIM results (Mailgun, SendGrid, Postmark) are about the envelope sender and some signing domain, not the From address, so they never vouch for anyone. `null` means the provider said nothing.
- **Delivery reports.** A bounce (an RFC 3464 `multipart/report`) is parsed into recipients, actions, status codes and diagnostics, and `DeliveryReport::eventFor()` calls a permanent failure a `hard_bounce` and a temporary one a `soft_bounce`.
- **Automatic-mail detection.** `AutomaticMail::reason()` says why a machine sent it: bounce, auto-submitted, bulk, auto-reply, mailing list, or one of your own addresses. `saysOutOfOffice()` is a separate, weaker question for you to weigh.
- **Threading references.** Amazon SES replaces your Message-ID, so a reply no longer carries your reference in its headers. Give the package a pattern for the link or token your emails quote, and it finds it anywhere in the message, quoted text included.

## Installation

```bash
composer require getodden/inbound-mail
php artisan vendor:publish --tag=inbound-mail-config   # optional
```

Requires PHP 8.3+ and Laravel 13.

## Use

```php
use Odden\InboundMail\AutomaticMail;
use Odden\InboundMail\Normalizer;

// A Mailgun route, a Postmark or SendGrid webhook, or your own JSON: the same message comes back.
$message = Normalizer::fromMailgun($request->all());     // or fromPostmark(), fromSendGrid(), fromGeneric()

// Amazon SES: the SNS notification's "Message" (decoded), and the raw message you fetched from S3.
$message = Normalizer::fromSes($notification, $rawMime);

if (! $message->hasSender()) {
    return; // no way to say who wrote it
}

if ($reason = AutomaticMail::reason($message, ownAddresses: ['support@example.com'])) {
    return; // a bounce, an auto-reply, a list: never answer it
}

$message->senderEmail();      // "pat@example.com", lower case
$message->body;               // the reply without the thread it quotes
$message->isVerified();       // true only on a DMARC pass or the provider's own word
$message->report?->recipients; // for a bounce: who it could not be delivered to
```

Threading by a reference your emails carry, for SES:

```php
// config/inbound-mail.php
'thread_token_patterns' => ['~support/tickets/([a-z0-9]{40})~i'],

$message->threadTokens;       // the references found anywhere in the message, quoted text included
```

## What it does not do yet

- **No receiving endpoint yet.** Verifying the SNS post, deduplicating it, fetching the message from a bounded S3 prefix and routing recipients is 0.2. For now you do that part, and hand the result to `fromSes()`.
- **No provider signature checks.** Mailgun, SendGrid and Postmark can sign their posts; verifying that is yours until a later version.
- **Real traffic:** Amazon SES and Mailgun have handled real mail in Odden Cloud; Postmark and SendGrid shapes are tested against fixtures only.

## License

MIT. Anything published here stays MIT; paid features live in the products built on top, never behind a license check in this package.
