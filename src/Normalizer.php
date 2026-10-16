<?php

namespace Odden\InboundMail;

use ZBateson\MailMimeParser\MailMimeParser;

/**
 * Turns what an email provider posts (Mailgun, Postmark, SendGrid, Amazon SES, or your own JSON) into one InboundMessage.
 */
final class Normalizer
{
    /**
     * Whether a payload is Mailgun's route format.
     *
     * @param  array<string, mixed>  $input
     */
    public static function looksLikeMailgun(array $input): bool
    {
        return isset($input['message-headers']) || isset($input['body-plain']);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromMailgun(array $input): InboundMessage
    {
        $headers = self::headers($input['message-headers'] ?? null);
        $message = new InboundMessage('', '', '', $headers);

        $text = self::string($input['stripped-text'] ?? null);
        $body = $text !== '' ? $text : self::string($input['body-plain'] ?? null);
        $body = $body !== '' ? $body : self::plain(self::string($input['body-html'] ?? $input['stripped-html'] ?? null));

        $score = $message->header('X-Mailgun-Sscore');

        return new InboundMessage(
            from: self::string($input['from'] ?? $input['sender'] ?? null),
            subject: self::string($input['subject'] ?? null) ?: 'No Subject',
            body: $body,
            headers: $headers,
            messageId: $message->header('Message-Id'),
            envelopeSender: self::string($input['sender'] ?? null),
            // Mailgun reports SPF and DKIM for the envelope sender and any signature, not for the From address: neither says who wrote
            // the message, so it vouches for nobody.
            authenticated: null,
            spamScore: is_numeric($score) ? (float) $score : null,
        );
    }

    /**
     * An Amazon SES receipt notification and the raw message it points at.
     *
     * @param  array<string, mixed>  $notification  The decoded SNS Message ("mail" and "receipt").
     */
    public static function fromSes(array $notification, string $raw): InboundMessage
    {
        $mail = is_array($notification['mail'] ?? null) ? $notification['mail'] : [];
        $receipt = is_array($notification['receipt'] ?? null) ? $notification['receipt'] : [];
        $common = is_array($mail['commonHeaders'] ?? null) ? $mail['commonHeaders'] : [];

        $parsed = (new MailMimeParser)->parse($raw, true);

        $headers = [];
        foreach ($parsed->getAllHeaders() as $header) {
            $headers[] = [$header->getName(), $header->getRawValue()];
        }

        $text = (string) $parsed->getTextContent();
        $html = (string) $parsed->getHtmlContent();
        $body = trim($text) !== '' ? self::withoutQuotedReply($text) : self::plain($html);

        // Looked for in the whole message, before the quoted thread is cut away from the body.
        $threadTokens = self::threadTokens($text.' '.$html);

        $report = null;
        if (stripos((string) $parsed->getHeaderValue('Content-Type'), 'multipart/report') === 0) {
            $status = null;
            $original = null;

            foreach ($parsed->getAllParts() as $part) {
                $type = strtolower((string) $part->getContentType());

                if ($type === 'message/delivery-status') {
                    $status = (string) $part->getContent();
                } elseif (in_array($type, ['message/rfc822', 'text/rfc822-headers'], true)) {
                    $original = (string) $part->getContent();
                }
            }

            $report = $status === null ? null : DeliveryReport::parse($status, $original);
        }

        $from = $parsed->getHeader('From')?->getRawValue() ?? (is_array($common['from'] ?? null) ? self::string($common['from'][0] ?? null) : '');
        $messageId = self::string($common['messageId'] ?? null) ?: ($parsed->getHeader('Message-ID')?->getRawValue() ?? null);

        $verdict = static fn (string $key): ?string => is_array($receipt[$key] ?? null) && is_string($receipt[$key]['status'] ?? null) ? strtoupper($receipt[$key]['status']) : null;
        // DMARC is the verdict about the From address: it passes only when SPF or DKIM passed for the From header's own domain. SPF
        // and DKIM alone are about the envelope sender and any signing domain, so they would vouch for a forged From.
        $dmarc = $verdict('dmarcVerdict');
        $spam = $verdict('spamVerdict');

        return new InboundMessage(
            from: $from,
            subject: trim((string) $parsed->getHeaderValue('Subject')) ?: 'No Subject',
            body: $body,
            headers: $headers,
            messageId: $messageId !== '' ? $messageId : null,
            envelopeSender: array_key_exists('source', $mail) ? self::string($mail['source']) : null,
            authenticated: $dmarc === null ? null : $dmarc === 'PASS',
            spamScore: match ($spam) {
                'FAIL' => max(10.0, (float) config('inbound-mail.spam_score_max', 5.0)),
                'PASS' => 0.0,
                default => null,
            },
            threadTokens: $threadTokens,
            report: $report,
        );
    }

    /**
     * What Postmark's inbound webhook posts (From, Subject, TextBody, StrippedTextReply, Headers, MessageID, SpamScore).
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromPostmark(array $input): InboundMessage
    {
        return self::fromGeneric($input);
    }

    /**
     * What SendGrid's Inbound Parse posts (from, subject, text, html, headers as a block of text, envelope as JSON).
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromSendGrid(array $input): InboundMessage
    {
        return self::fromGeneric($input);
    }

    /**
     * Your own JSON (from, subject, body, headers, in_reply_to, references, sender_authenticated), plus what Postmark and SendGrid send.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromGeneric(array $input): InboundMessage
    {
        $headers = self::headers($input['headers'] ?? $input['Headers'] ?? null);

        foreach (['In-Reply-To' => ['in_reply_to', 'In-Reply-To'], 'References' => ['references', 'References']] as $name => $fields) {
            foreach ($fields as $field) {
                if (is_string($input[$field] ?? null) && $input[$field] !== '') {
                    $headers[] = [$name, $input[$field]];
                }
            }
        }

        $message = new InboundMessage('', '', '', $headers);

        $body = '';
        foreach (['body', 'text', 'StrippedTextReply', 'TextBody', 'stripped-text'] as $field) {
            if (($body = self::string($input[$field] ?? null)) !== '') {
                break;
            }
        }
        $body = $body !== '' ? $body : self::plain(self::string($input['html'] ?? $input['body_html'] ?? $input['HtmlBody'] ?? null));

        $score = $input['SpamScore'] ?? $message->header('X-Spam-Score');
        $envelope = is_string($input['envelope'] ?? null) ? json_decode($input['envelope'], true) : null;

        return new InboundMessage(
            from: self::string($input['from'] ?? $input['From'] ?? $input['sender'] ?? null),
            subject: self::string($input['subject'] ?? $input['Subject'] ?? null) ?: 'No Subject',
            body: $body,
            headers: $headers,
            messageId: self::string($input['MessageID'] ?? null) ?: $message->header('Message-Id'),
            envelopeSender: is_array($envelope) && is_string($envelope['from'] ?? null) ? $envelope['from'] : null,
            authenticated: self::verdict($input),
            spamScore: is_numeric($score) ? (float) $score : null,
        );
    }

    /**
     * Whether the provider says the sender passed authentication, or null if it says nothing.
     *
     * @param  array<string, mixed>  $input
     */
    private static function verdict(array $input): ?bool
    {
        $says = fn (mixed $value): ?bool => is_scalar($value)
            ? in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on', 'pass'], true)
            : null;

        if (isset($input['sender_authenticated'])) {
            return $says($input['sender_authenticated']);
        }

        if (isset($input['dmarc'])) {
            return $says($input['dmarc']);
        }

        // SendGrid's SPF and DKIM results and Postmark's Received-SPF header are about the envelope sender and some signature, not
        // the From address, so they are not taken as the sender being who they say.
        return null;
    }

    /**
     * Header name and value pairs from a JSON list, a raw header block, a map, or a list of {Name, Value}.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function headers(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (is_array($decoded)) {
                return self::headers($decoded);
            }

            $unfolded = (string) preg_replace('/\r?\n[ \t]+/', ' ', $value);
            preg_match_all('/^([A-Za-z0-9-]+):[ \t]*(.*)$/m', $unfolded, $matches, PREG_SET_ORDER);

            return array_map(fn (array $match): array => [$match[1], trim($match[2])], $matches);
        }

        if (! is_array($value)) {
            return [];
        }

        $pairs = [];

        foreach ($value as $name => $item) {
            if (is_int($name) && is_array($item) && array_is_list($item) && count($item) === 2) {
                [$name, $item] = $item;
            } elseif (is_int($name) && is_array($item) && (isset($item['Name']) || isset($item['name']))) {
                [$name, $item] = [$item['Name'] ?? $item['name'], $item['Value'] ?? $item['value'] ?? ''];
            }

            if (is_string($name) && (is_string($item) || is_numeric($item))) {
                $pairs[] = [$name, (string) $item];
            }
        }

        return $pairs;
    }

    /**
     * A plain-text reply without the thread it quotes: from the "On ... wrote:" line (or the first line of "> " quotes) to the end.
     * The text is returned whole when nothing but quoting would be left.
     */
    private static function withoutQuotedReply(string $text): string
    {
        $lines = preg_split('/\R/', $text) ?: [];
        $cut = null;

        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*(>|-{2,}\s*Original Message\s*-{2,}|On .{5,200} wrote:\s*$)/i', $line) === 1) {
                $cut = $i;
                break;
            }
        }

        $kept = $cut === null ? $text : trim(implode("\n", array_slice($lines, 0, $cut)));

        return $kept !== '' ? $kept : $text;
    }

    /**
     * The tokens the configured patterns find (each pattern's first group), without repeats.
     *
     * @return list<string>
     */
    private static function threadTokens(string $text): array
    {
        $tokens = [];

        foreach ((array) config('inbound-mail.thread_token_patterns', []) as $pattern) {
            if (is_string($pattern) && @preg_match_all($pattern, $text, $found) !== false) {
                $tokens = [...$tokens, ...($found[1] ?? $found[0])];
            }
        }

        return array_values(array_unique($tokens));
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private static function plain(string $html): string
    {
        $text = preg_replace(['~<(script|style)\b.*?</\1>~is', '~<br\s*/?>|</p>|</div>|</li>~i'], ['', "\n"], $html);

        return trim(html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5));
    }
}
