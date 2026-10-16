<?php

namespace Odden\InboundMail;

/**
 * What a delivery status notification (RFC 3464, a multipart/report) says: who the message could not be delivered to and why,
 * and who the returned message was from.
 */
final readonly class DeliveryReport
{
    /**
     * @param  list<array{recipient: string, action: string, status: string, diagnostic: string}>  $recipients
     */
    public function __construct(public array $recipients, public ?string $originalFrom = null) {}

    /**
     * @param  string  $statusText  The body of the message/delivery-status part.
     * @param  string|null  $original  The returned message or its headers (message/rfc822 or text/rfc822-headers).
     */
    public static function parse(string $statusText, ?string $original = null): ?self
    {
        $recipients = [];

        // Groups of fields separated by blank lines: the first is about the message, the others each about one recipient.
        foreach (preg_split('/\R{2,}/', trim((string) preg_replace('/\r?\n[ \t]+/', ' ', $statusText))) ?: [] as $group) {
            $fields = [];

            foreach (preg_split('/\R/', $group) ?: [] as $line) {
                if (preg_match('/^([A-Za-z-]+):\s*(.*)$/', $line, $m) === 1) {
                    $fields[strtolower($m[1])] = trim($m[2]);
                }
            }

            $recipient = $fields['final-recipient'] ?? null;

            if ($recipient === null) {
                continue;
            }

            $address = strtolower(trim((string) preg_replace('/^[a-z0-9-]+;\s*/i', '', $recipient)));

            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }

            $recipients[] = [
                'recipient' => $address,
                'action' => strtolower($fields['action'] ?? ''),
                'status' => $fields['status'] ?? '',
                'diagnostic' => mb_substr($fields['diagnostic-code'] ?? '', 0, 500),
            ];
        }

        if ($recipients === []) {
            return null;
        }

        $from = null;

        if ($original !== null && preg_match('/^From:\s*(.+)$/mi', $original, $m) === 1) {
            $parsed = SenderAddress::parse(trim($m[1]));
            $from = $parsed->email;
        }

        return new self($recipients, $from);
    }

    /**
     * "hard_bounce" for a permanent failure (Action failed, class 5), "soft_bounce" for a temporary one (Action failed, class 4),
     * null for anything else (delayed, delivered, relayed, expanded).
     *
     * @param  array{recipient: string, action: string, status: string, diagnostic: string}  $entry
     */
    public static function eventFor(array $entry): ?string
    {
        if ($entry['action'] !== 'failed') {
            return null;
        }

        return match ($entry['status'][0] ?? '') {
            '5' => 'hard_bounce',
            '4' => 'soft_bounce',
            default => null,
        };
    }
}
