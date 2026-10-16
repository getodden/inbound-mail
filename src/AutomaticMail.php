<?php

namespace Odden\InboundMail;

/**
 * Whether a machine sent an email, so it should not open a ticket, start a conversation or get an answer (an answer to an auto-reply is
 * how two systems write to each other forever).
 *
 * Bounces come first, then the headers that say "automatic": Auto-Submitted, Precedence, the autoreply headers and List-Id. Mail from one of
 * the application's own addresses is a machine too (it would answer itself). A subject that reads like an out-of-office is weak evidence on
 * its own, so it is a separate question: {@see self::saysOutOfOffice()}, for the application to weigh (for example, only for mail that does
 * not answer a conversation it has).
 */
final class AutomaticMail
{
    /**
     * Why the message is machine-made: bounce, auto-submitted, bulk, auto-reply, mailing list or own address; or null.
     *
     * @param  list<string>  $ownAddresses  The application's own addresses (lower case): mail from them is its own.
     */
    public static function reason(InboundMessage $message, array $ownAddresses = []): ?string
    {
        $auto = $message->header('Auto-Submitted');
        $precedence = strtolower((string) $message->header('Precedence'));

        $bounce = $message->envelopeSender !== null && in_array(trim($message->envelopeSender), ['', '<>'], true);
        $daemon = (bool) preg_match('/^(mailer-daemon|postmaster)@/', $message->senderEmail());
        $report = (bool) preg_match('~^\s*multipart/report\b~i', (string) $message->header('Content-Type'));
        $own = in_array($message->senderEmail(), array_map('mb_strtolower', $ownAddresses), true);

        return match (true) {
            $bounce || $daemon || $report => 'bounce',
            $auto !== null && strtolower($auto) !== 'no' => 'auto-submitted',
            in_array($precedence, ['bulk', 'junk', 'list', 'auto_reply'], true) => 'bulk',
            $message->hasHeader('X-Auto-Response-Suppress') || $message->hasHeader('X-Autoreply') || $message->hasHeader('X-Autorespond') => 'auto-reply',
            $message->hasHeader('List-Id') => 'mailing list',
            $own => 'own address',
            default => null,
        };
    }

    /**
     * A subject that starts the way automatic replies do ("Automatic reply:", "Out of office", "Away from my desk"), after any Re: or Fwd:.
     */
    public static function saysOutOfOffice(InboundMessage $message): bool
    {
        return preg_match('/^\s*(?:(?:re|aw|fw|fwd)\s*:\s*)*(?:automatic(?:ally generated)? (?:reply|response)|auto[- ]?(?:reply|response)|out of (?:the )?office|away from (?:my )?(?:desk|office))\b/i', $message->subject) === 1;
    }
}
