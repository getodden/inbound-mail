<?php

namespace Odden\InboundMail\Contracts;

use Odden\InboundMail\InboundMessage;

/**
 * What an inbound message does is the application's to say: open a ticket, add a note, drop it.
 */
interface HandlesInboundMail
{
    /**
     * Deliver a message to one of the targets ResolvesRecipients returned. A thrown exception makes the endpoint answer 500, so SNS
     * sends the notification again; return normally for anything that should not be retried, including a message you refuse.
     *
     * @return array<string, mixed> Reported in the endpoint's JSON answer.
     */
    public function handle(InboundMessage $message, mixed $target): array;
}
