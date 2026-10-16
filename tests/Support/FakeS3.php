<?php

namespace Odden\InboundMail\Tests\Support;

use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Promise\Create;
use RuntimeException;

final class FakeS3
{
    /**
     * An S3 client that serves the given bodies and records the keys asked for.
     *
     * @param  array<string, string>  $objects
     * @param  list<string>  $asked
     */
    public static function client(array $objects, array &$asked): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => 'us-east-2',
            'credentials' => ['key' => 'k', 'secret' => 's'],
            'handler' => function ($command) use ($objects, &$asked) {
                $asked[] = $command['Key'];
                $body = $objects[$command['Key']] ?? null;

                return Create::promiseFor($body === null
                    ? throw new RuntimeException('NoSuchKey')
                    : new Result(['Body' => $body, 'ContentLength' => strlen($body)]));
            },
        ]);
    }
}
