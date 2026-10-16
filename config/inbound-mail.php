<?php

return [
    /*
    | The spam score a provider's "spam" verdict is turned into when it fails (Amazon SES gives a verdict, not a score): at least this
    | much, so an application's own threshold treats it as spam.
    */
    'spam_score_max' => 5.0,

    /*
    | Regular expressions that find the references your own emails carry, so a reply can be threaded when the provider has replaced your
    | Message-ID (Amazon SES does). Each is run over the whole message, quoted text included, and its first capture group becomes an entry
    | of InboundMessage::$threadTokens. Example: a ticket link, '~support/tickets/([a-z0-9]{40})~i'.
    */
    'thread_token_patterns' => [],

    /*
    | The Amazon SES receiving endpoint. SES stores a received message in S3 and tells an SNS topic; SNS posts the notification to a route
    | this package registers (when route.enabled is true). Nothing in a request is read until SNS's signature is proved, using the
    | verifier from getodden/ses-tenants, and the raw message is fetched only from the bucket and prefix named here.
    |
    | topics: the SNS topic ARNs accepted. With none, the route answers 404.
    | bucket, prefix: where SES leaves the messages. Only keys under the prefix, in this bucket, are ever read.
    | region, key, secret: for reading the bucket; leave key and secret empty to use the AWS SDK's default credentials.
    | max_bytes: a larger message is dropped (it will never succeed, so SNS is told 200).
    | max_age_seconds, require_signature_v2, verify_chain, chain_roots, chain_issuer_hosts: as in ses-tenants' sns settings.
    | dedupe_days: how long an SNS message id is remembered, since SNS delivers at least once.
    */
    'ses' => [
        'topics' => array_values(array_filter(array_map('trim', explode(',', (string) env('INBOUND_MAIL_SES_TOPICS', ''))))),
        'bucket' => env('INBOUND_MAIL_SES_BUCKET'),
        'prefix' => env('INBOUND_MAIL_SES_PREFIX', 'inbound/'),
        'region' => env('INBOUND_MAIL_SES_REGION'),
        'key' => env('INBOUND_MAIL_SES_KEY'),
        'secret' => env('INBOUND_MAIL_SES_SECRET'),
        'max_bytes' => 10 * 1024 * 1024,
        'max_age_seconds' => 86400,
        'require_signature_v2' => (bool) env('INBOUND_MAIL_SES_REQUIRE_V2', false),
        'verify_chain' => (bool) env('INBOUND_MAIL_SES_VERIFY_CHAIN', true),
        'chain_roots' => env('INBOUND_MAIL_SES_CHAIN_ROOTS'),
        'chain_issuer_hosts' => ['amazontrust.com', 'digicert.com'],
        'dedupe_days' => 7,

        'route' => [
            'enabled' => (bool) env('INBOUND_MAIL_SES_ROUTE', false),
            'path' => 'inbound/email/ses/mail',
            'domain' => null,
            'middleware' => ['throttle:120,1'],
        ],
    ],
];
