<?php

namespace Odden\InboundMail\Tests;

/**
 * An application that has turned the SES endpoint on.
 */
abstract class SesTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('inbound-mail.ses.route.enabled', true);
        $app['config']->set('inbound-mail.ses.topics', ['arn:aws:sns:us-east-2:123456789012:inbound']);
        $app['config']->set('inbound-mail.ses.bucket', 'inbound-bucket');
        $app['config']->set('inbound-mail.ses.region', 'us-east-2');
        $app['config']->set('cache.default', 'array');
    }
}
