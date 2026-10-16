<?php

namespace Odden\InboundMail;

/**
 * An inbound email in one shape, whichever provider sent it: SES, Mailgun, Postmark, SendGrid or your own JSON.
 */
final readonly class InboundMessage
{
    /** The one address the From header names (see SenderAddress). */
    public SenderAddress $sender;

    /**
     * @param  list<array{0: string, 1: string}>  $headers  Header name and value pairs, in order.
     * @param  string|null  $envelopeSender  The SMTP "MAIL FROM"; empty for a bounce.
     * @param  bool|null  $authenticated  Whether the message is vouched for as sent by its From address: a DMARC pass (SPF or DKIM
     *                                    aligned with the From domain), or the provider's own statement. null when it says nothing.
     * @param  list<string>  $threadTokens  Tokens found in the whole message, quoted text included (see inbound-mail.thread_token_patterns). Some providers
     *                                      (SES) replace your Message-ID, so a reply no longer carries your reference in its headers;
     *                                      a link in the text it quotes is what is left to thread it by.
     * @param  DeliveryReport|null  $report  What a delivery status notification (multipart/report) says, when the message is one.
     */
    public function __construct(
        public string $from,
        public string $subject,
        public string $body,
        public array $headers = [],
        public ?string $messageId = null,
        public ?string $envelopeSender = null,
        public ?bool $authenticated = null,
        public ?float $spamScore = null,
        public array $threadTokens = [],
        public ?DeliveryReport $report = null,
    ) {
        $this->sender = SenderAddress::parse($from);
    }

    /**
     * Whether the From header names exactly one valid address.
     */
    public function hasSender(): bool
    {
        return $this->sender->email !== null;
    }

    /**
     * Whether the message is vouched for as really sent from its From address.
     */
    public function isVerified(): bool
    {
        return $this->authenticated === true;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as [$key, $value]) {
            if (strcasecmp(trim($key), $name) === 0) {
                return trim($value);
            }
        }

        return null;
    }

    public function hasHeader(string $name): bool
    {
        return $this->header($name) !== null;
    }

    /**
     * The sender's address, lower case.
     */
    public function senderEmail(): string
    {
        return $this->sender->email ?? '';
    }
}
