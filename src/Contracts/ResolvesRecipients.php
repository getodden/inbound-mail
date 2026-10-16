<?php

namespace Odden\InboundMail\Contracts;

/**
 * Which inbox, workspace or handler a recipient address belongs to is the application's to say.
 */
interface ResolvesRecipients
{
    /**
     * The targets a recipient address is delivered to, each under a stable key (so an address that reaches the same target twice
     * is delivered once). An empty array means no inbox holds the address.
     *
     * @return array<string, mixed>
     */
    public function resolve(string $recipient): array;
}
