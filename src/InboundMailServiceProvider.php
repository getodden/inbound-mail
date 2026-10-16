<?php

namespace Odden\InboundMail;

use Illuminate\Support\ServiceProvider;

class InboundMailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/inbound-mail.php', 'inbound-mail');
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/inbound-mail.php' => config_path('inbound-mail.php')], 'inbound-mail-config');
    }
}
