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
];
