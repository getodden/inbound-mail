<?php

namespace Odden\InboundMail\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Odden\InboundMail\Ses\SesReceiver;
use Odden\SesTenants\Exceptions\SnsException;
use Odden\SesTenants\Http\SesEventsController;
use Odden\SesTenants\Sns\SnsVerifier;
use Throwable;

/**
 * SES stores a received message in S3 and tells an SNS topic; SNS posts the notification here. Nothing in the request is read
 * until SNS's signature is proved. Everything handled, ignored or already seen answers 200, so SNS stops retrying; a failure to read
 * the message or to do the work answers 500, so it is sent again.
 */
class SesInboundController
{
    public function __invoke(Request $request, SesReceiver $receiver): JsonResponse
    {
        $topics = array_values(array_map('strval', (array) config('inbound-mail.ses.topics', [])));

        abort_if($topics === [], 404);

        $message = json_decode($request->getContent(), true);

        if (! is_array($message)) {
            return response()->json(['error' => 'Not a message.'], 400);
        }

        try {
            $this->verifier($topics)->verify($message);
        } catch (SnsException $e) {
            Log::warning('Refused an inbound SNS message: '.$e->getMessage());

            return response()->json(['error' => 'Not verified.'], 403);
        }

        if (($message['Type'] ?? null) === 'SubscriptionConfirmation') {
            return SesEventsController::confirm($message);
        }

        if (($message['Type'] ?? null) !== 'Notification') {
            return response()->json(['status' => 'ignored']);
        }

        // Cache::add is false when this message id was already handled: SNS delivers at least once.
        $marker = 'inbound-mail:sns:'.hash('sha256', (string) $message['MessageId']);

        if (! Cache::add($marker, true, (int) config('inbound-mail.ses.dedupe_days', 7) * 86400)) {
            return response()->json(['status' => 'duplicate']);
        }

        try {
            return response()->json($receiver->receive($message));
        } catch (Throwable $e) {
            // Answer 500 so SNS sends it again, and have it counted as new when it does.
            Cache::forget($marker);

            throw $e;
        }
    }

    /**
     * @param  list<string>  $topics
     */
    private function verifier(array $topics): SnsVerifier
    {
        $config = (array) config('inbound-mail.ses');

        return new SnsVerifier(
            $topics,
            (int) ($config['max_age_seconds'] ?? 86400),
            (bool) ($config['require_signature_v2'] ?? false),
            (bool) ($config['verify_chain'] ?? true),
            ($config['chain_roots'] ?? null) ?: null,
            array_values(array_map('strval', (array) ($config['chain_issuer_hosts'] ?? ['amazontrust.com', 'digicert.com']))),
        );
    }
}
