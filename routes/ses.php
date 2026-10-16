<?php

use Illuminate\Support\Facades\Route;
use Odden\InboundMail\Http\SesInboundController;

$route = Route::post((string) config('inbound-mail.ses.route.path'), SesInboundController::class)
    ->middleware((array) config('inbound-mail.ses.route.middleware', []))
    ->name('inbound-mail.ses');

if (is_string($domain = config('inbound-mail.ses.route.domain')) && $domain !== '') {
    $route->domain($domain);
}
