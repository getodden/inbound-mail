<?php

namespace Odden\InboundMail\Ses;

use Odden\InboundMail\Contracts\HandlesInboundMail;
use Odden\InboundMail\Contracts\ResolvesRecipients;
use Odden\InboundMail\Normalizer;

/**
 * What a verified SNS notification from SES does: check it is received mail that passed the virus scan, find who the recipients
 * belong to, read the message from the bucket, normalise it and hand it over. The result is the endpoint's JSON answer.
 */
class SesReceiver
{
    public function __construct(
        private readonly ResolvesRecipients $recipients,
        private readonly HandlesInboundMail $handler,
        private readonly RawMailStore $store,
    ) {}

    /**
     * @param  array<string, mixed>  $message  The SNS envelope, already verified.
     * @return array<string, mixed>
     */
    public function receive(array $message): array
    {
        $notification = json_decode((string) ($message['Message'] ?? ''), true);
        $receipt = is_array($notification) && is_array($notification['receipt'] ?? null) ? $notification['receipt'] : null;
        $action = is_array($receipt['action'] ?? null) ? $receipt['action'] : [];

        if ($receipt === null || ($notification['notificationType'] ?? null) !== 'Received' || ($action['type'] ?? null) !== 'S3') {
            return ['status' => 'ignored', 'reason' => 'not mail'];
        }

        if (strtoupper((string) ($receipt['virusVerdict']['status'] ?? '')) === 'FAIL') {
            return ['status' => 'ignored', 'reason' => 'virus'];
        }

        $targets = [];
        foreach ((array) ($receipt['recipients'] ?? []) as $recipient) {
            if (is_string($recipient)) {
                $targets = [...$targets, ...$this->recipients->resolve($recipient)];
            }
        }

        if ($targets === []) {
            return ['status' => 'ignored', 'reason' => 'no inbox'];
        }

        $raw = $this->store->fetch((string) ($action['bucketName'] ?? ''), (string) ($action['objectKey'] ?? ''));

        if ($raw === null) {
            return ['status' => 'ignored', 'reason' => 'unreadable'];
        }

        $inbound = Normalizer::fromSes($notification, $raw);
        $results = [];

        foreach ($targets as $target) {
            $results[] = $this->handler->handle($inbound, $target);
        }

        return $results[0] + ['deliveries' => count($results)];
    }
}
