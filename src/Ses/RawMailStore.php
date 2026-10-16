<?php

namespace Odden\InboundMail\Ses;

use Aws\S3\S3Client;

/**
 * Where SES leaves received mail. Only objects under the configured prefix, in the configured bucket, are ever read, whatever a
 * notification says.
 */
class RawMailStore
{
    public function __construct(private ?S3Client $client = null) {}

    /**
     * The raw message for an object key, or null when the key is outside the store or the message is larger than the limit
     * (neither will ever succeed, so the caller drops them). A failure to read it throws, so the notification is retried.
     */
    public function fetch(string $bucket, string $key): ?string
    {
        if ($this->refuses($bucket, $key)) {
            return null;
        }

        $result = $this->client()->getObject(['Bucket' => $bucket, 'Key' => $key]);

        if ((int) ($result['ContentLength'] ?? 0) > (int) config('inbound-mail.ses.max_bytes', 10 * 1024 * 1024)) {
            return null;
        }

        return (string) $result['Body'];
    }

    /**
     * Whether an object is outside the store: another bucket, or a key outside the prefix.
     */
    public function refuses(string $bucket, string $key): bool
    {
        $prefix = (string) config('inbound-mail.ses.prefix', '');

        return $bucket === '' || $bucket !== config('inbound-mail.ses.bucket') || ($prefix !== '' && ! str_starts_with($key, $prefix)) || str_contains($key, '..');
    }

    private function client(): S3Client
    {
        $key = (string) config('inbound-mail.ses.key');
        $secret = (string) config('inbound-mail.ses.secret');

        return $this->client ??= new S3Client(array_filter([
            'version' => 'latest',
            'region' => (string) config('inbound-mail.ses.region'),
            'credentials' => $key !== '' && $secret !== '' ? ['key' => $key, 'secret' => $secret] : null,
        ]));
    }
}
