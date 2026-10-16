<?php

use Odden\InboundMail\Tests\SesTestCase;
use Odden\InboundMail\Tests\TestCase;

pest()->extend(TestCase::class)->in('Unit', 'Feature/SesRouteTest.php');
pest()->extend(SesTestCase::class)->in('Feature/SesEndpointTest.php');
