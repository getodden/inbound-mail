<?php

namespace Odden\InboundMail\Tests\Support;

use Odden\InboundMail\Contracts\HandlesInboundMail;
use Odden\InboundMail\Contracts\ResolvesRecipients;
use Odden\InboundMail\InboundMessage;

/**
 * A recipient list the tests control, and a handler that remembers what it was given.
 */
final class Inboxes implements HandlesInboundMail, ResolvesRecipients
{
    /** @var array<string, array<string, mixed>> */
    public array $addresses = [];

    /** @var list<array{0: InboundMessage, 1: mixed}> */
    public array $delivered = [];

    public ?\Throwable $failure = null;

    public function resolve(string $recipient): array
    {
        return $this->addresses[strtolower($recipient)] ?? [];
    }

    public function handle(InboundMessage $message, mixed $target): array
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $this->delivered[] = [$message, $target];

        return ['status' => 'created', 'target' => $target];
    }
}
