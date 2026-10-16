<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Odden\InboundMail\Contracts\HandlesInboundMail;
use Odden\InboundMail\Contracts\ResolvesRecipients;
use Odden\InboundMail\Ses\RawMailStore;
use Odden\InboundMail\Tests\Support\FakeS3;
use Odden\InboundMail\Tests\Support\Inboxes;

const SES_TOPIC = 'arn:aws:sns:us-east-2:123456789012:inbound';
const SES_CERT = 'https://sns.us-east-2.amazonaws.com/SimpleNotificationService-test123.pem';
const SES_RAW = "From: Ada <ada@customer.test>\r\nTo: support@inbound.test\r\nSubject: Help\r\nMessage-ID: <1@customer.test>\r\nContent-Type: text/plain\r\n\r\nPlease help.\r\n";

beforeEach(function (): void {
    Cache::flush();

    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'sns.us-east-2.amazonaws.com'], $key);
    openssl_x509_export(openssl_csr_sign($csr, null, $key, 1), $pem);
    // The test certificate is its own authority: the chain check is covered in ses-tenants' SnsVerifier tests.
    file_put_contents($roots = tempnam(sys_get_temp_dir(), 'sns-roots'), $pem);
    config(['inbound-mail.ses.chain_roots' => $roots]);
    $this->snsKey = $key;
    Http::fake([SES_CERT => Http::response($pem), 'https://sns.us-east-2.amazonaws.com/confirm*' => Http::response('ok')]);

    $this->inboxes = new Inboxes;
    $this->inboxes->addresses['support@inbound.test'] = ['acme:support' => 'acme:support'];
    $this->asked = [];
    $this->objects = ['inbound/abc' => SES_RAW];

    $this->app->instance(ResolvesRecipients::class, $this->inboxes);
    $this->app->instance(HandlesInboundMail::class, $this->inboxes);
    $this->app->instance(RawMailStore::class, new RawMailStore(FakeS3::client($this->objects, $this->asked)));
});

function sesSigned(array $message): array
{
    $keys = $message['Type'] === 'Notification'
        ? ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type']
        : ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];

    $toSign = '';
    foreach ($keys as $key) {
        if (isset($message[$key])) {
            $toSign .= $key."\n".$message[$key]."\n";
        }
    }

    openssl_sign($toSign, $signature, test()->snsKey, OPENSSL_ALGO_SHA256);

    return [...$message, 'Signature' => base64_encode($signature)];
}

/** A notification SES sends for a stored message. */
function sesNotification(array $receipt = [], array $overrides = []): array
{
    $body = [
        'notificationType' => 'Received',
        'mail' => ['commonHeaders' => ['from' => ['Ada <ada@customer.test>']]],
        'receipt' => [
            'recipients' => ['support@inbound.test'],
            'spfVerdict' => ['status' => 'PASS'],
            'dkimVerdict' => ['status' => 'PASS'],
            'dmarcVerdict' => ['status' => 'PASS'],
            'virusVerdict' => ['status' => 'PASS'],
            'action' => ['type' => 'S3', 'bucketName' => 'inbound-bucket', 'objectKey' => 'inbound/abc'],
            ...$receipt,
        ],
        ...$overrides,
    ];

    return sesSigned([
        'Type' => 'Notification',
        'MessageId' => (string) Str::uuid(),
        'TopicArn' => SES_TOPIC,
        'Message' => json_encode($body),
        'Timestamp' => now()->toIso8601ZuluString('millisecond'),
        'SignatureVersion' => '2',
        'SigningCertURL' => SES_CERT,
    ]);
}

function postSes(array $message)
{
    return test()->call('POST', '/inbound/email/ses/mail', [], [], [], ['CONTENT_TYPE' => 'text/plain; charset=UTF-8'], json_encode($message));
}

it('hands a signed notification\'s message to the application, parsed', function (): void {
    postSes(sesNotification())->assertOk()->assertJson(['status' => 'created', 'target' => 'acme:support', 'deliveries' => 1]);

    expect($this->inboxes->delivered)->toHaveCount(1);
    [$message, $target] = $this->inboxes->delivered[0];
    expect($target)->toBe('acme:support')
        ->and($message->subject)->toBe('Help')
        ->and($message->sender->canonical())->toContain('ada@customer.test')
        ->and($message->authenticated)->toBeTrue();
});

it('answers 404 with no topic configured, 400 for a body that is not a message and 403 for one that is not signed', function (): void {
    postSes(['Type' => 'Notification', 'TopicArn' => SES_TOPIC])->assertForbidden();
    $this->call('POST', '/inbound/email/ses/mail', [], [], [], [], 'not json')->assertStatus(400);

    config(['inbound-mail.ses.topics' => []]);
    postSes(sesNotification())->assertNotFound();

    expect($this->inboxes->delivered)->toBeEmpty();
});

it('refuses a message signed for another topic, or altered after signing', function (): void {
    $other = sesSigned([...json_decode(json_encode(sesNotification()), true), 'TopicArn' => 'arn:aws:sns:us-east-2:123456789012:other', 'Signature' => null]);
    postSes($other)->assertForbidden();

    $tampered = sesNotification();
    $tampered['Message'] = str_replace('support@inbound.test', 'ceo@inbound.test', $tampered['Message']);
    postSes($tampered)->assertForbidden();

    expect($this->inboxes->delivered)->toBeEmpty();
});

it('confirms the topic\'s subscription, but only at an Amazon SNS address', function (): void {
    $confirm = fn (string $url) => sesSigned([
        'Type' => 'SubscriptionConfirmation', 'MessageId' => (string) Str::uuid(), 'TopicArn' => SES_TOPIC, 'Message' => 'hi', 'Token' => 't',
        'SubscribeURL' => $url, 'Timestamp' => now()->toIso8601ZuluString('millisecond'), 'SignatureVersion' => '2', 'SigningCertURL' => SES_CERT,
    ]);

    postSes($confirm('https://sns.us-east-2.amazonaws.com/confirm?x=1'))->assertOk()->assertJson(['status' => 'confirmed']);
    postSes($confirm('https://evil.example/confirm'))->assertOk()->assertJson(['status' => 'ignored']);
});

it('does not deliver a notification twice', function (): void {
    $message = sesNotification();

    postSes($message)->assertOk()->assertJson(['status' => 'created']);
    postSes($message)->assertOk()->assertJson(['status' => 'duplicate']);

    expect($this->inboxes->delivered)->toHaveCount(1);
});

it('ignores what is not stored mail, mail that failed the virus scan, and addresses nobody holds', function (): void {
    postSes(sesNotification([], ['notificationType' => 'AmazonSnsSubscriptionSucceeded']))->assertOk()->assertJson(['reason' => 'not mail']);
    postSes(sesNotification(['action' => ['type' => 'SNS']]))->assertOk()->assertJson(['reason' => 'not mail']);
    postSes(sesNotification(['virusVerdict' => ['status' => 'FAIL']]))->assertOk()->assertJson(['reason' => 'virus']);
    postSes(sesNotification(['recipients' => ['nobody@inbound.test']]))->assertOk()->assertJson(['reason' => 'no inbox']);

    expect($this->inboxes->delivered)->toBeEmpty()->and($this->asked)->toBeEmpty();
});

it('reads only from its own bucket and prefix, whatever a notification says', function (): void {
    foreach ([
        ['bucketName' => 'someone-elses-bucket', 'objectKey' => 'inbound/abc'],
        ['bucketName' => 'inbound-bucket', 'objectKey' => 'private/abc'],
        ['bucketName' => 'inbound-bucket', 'objectKey' => 'inbound/../private/abc'],
    ] as $where) {
        postSes(sesNotification(['action' => ['type' => 'S3', ...$where]]))->assertOk()->assertJson(['reason' => 'unreadable']);
    }

    expect($this->asked)->toBeEmpty()->and($this->inboxes->delivered)->toBeEmpty();
});

it('drops a message larger than the limit', function (): void {
    config(['inbound-mail.ses.max_bytes' => 10]);

    postSes(sesNotification())->assertOk()->assertJson(['reason' => 'unreadable']);

    expect($this->inboxes->delivered)->toBeEmpty();
});

it('delivers once per target, however many addresses reach it', function (): void {
    $this->inboxes->addresses['help@inbound.test'] = ['acme:support' => 'acme:support'];
    $this->inboxes->addresses['sales@inbound.test'] = ['acme:sales' => 'acme:sales'];

    postSes(sesNotification(['recipients' => ['support@inbound.test', 'help@inbound.test', 'sales@inbound.test']]))->assertOk()->assertJson(['deliveries' => 2]);

    expect(array_column($this->inboxes->delivered, 1))->toBe(['acme:support', 'acme:sales']);
});

it('answers 500 when delivery fails and delivers the notification when SNS sends it again', function (): void {
    $message = sesNotification();
    $this->inboxes->failure = new RuntimeException('database went away');

    postSes($message)->assertStatus(500);
    expect($this->inboxes->delivered)->toBeEmpty();

    $this->inboxes->failure = null;
    postSes($message)->assertOk()->assertJson(['status' => 'created']);
    postSes($message)->assertOk()->assertJson(['status' => 'duplicate']);
});

it('answers 500 when the bucket cannot be read, so the notification is sent again', function (): void {
    $this->app->instance(RawMailStore::class, new RawMailStore(FakeS3::client([], $this->asked)));

    postSes(sesNotification())->assertStatus(500);
});
