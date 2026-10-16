# Receiving mail with Amazon SES

The AWS side of the SES endpoint: an MX record, a verified identity, an S3 bucket, an SNS topic, a receipt rule and a read-only IAM policy. This is the setup Odden Cloud receives its own mail through. The application side is in the README ("Receive from Amazon SES").

## How a message travels

1. A sender's mail (or a forward from Microsoft 365 or Google Workspace) reaches the MX record of your inbound domain, which is SES.
2. A receipt rule stores the raw message in S3 under a prefix and notifies an SNS topic (the topic is set on the rule's S3 action).
3. SNS posts the notification to your endpoint.
4. The package verifies the SNS signature and the topic, fetches the message from the configured bucket and prefix only, parses it and hands it to your code.

## Variables

```bash
export REGION=us-east-1            # a region where SES supports receiving; check the SES docs
export ACCOUNT=123456789012
export BUCKET=example-inbound-mail
export TOPIC_NAME=inbound-mail
export DOMAIN=inbound.example.com
```

The bucket, the SNS topic and the SES receipt rule must all be in the same region.

## 1. DNS: point the inbound domain at SES

| Name | Type | Priority | Value |
| :--- | :--- | :--- | :--- |
| `inbound.example.com` | MX | 10 | `inbound-smtp.<REGION>.amazonaws.com` |

- Use a subdomain, so the MX record of your main domain does not change.
- A resolver that looked the name up before the record existed may keep answering "no such record" for up to the zone's negative-caching TTL, so a test sent straight after adding the record can fail even though the record is right.

## 1b. Verify the domain in SES

SES accepts mail only for a domain that is a verified identity in the same region as the receipt rule. A rule alone is not enough: without the identity, SES answers every message with `550 5.1.1 Requested action not taken: mailbox unavailable`.

```bash
aws sesv2 create-email-identity --email-identity $DOMAIN --dkim-signing-attributes NextSigningKeyLength=RSA_2048_BIT \
  --query 'DkimAttributes.Tokens' --output json
```

Add one CNAME per token to the zone (DNS only, not proxied), then wait for SES to see them:

| Name | Target |
| :--- | :--- |
| `<token>._domainkey.inbound` (in the `example.com` zone) | `<token>.dkim.amazonses.com` |

```bash
aws sesv2 get-email-identity --email-identity $DOMAIN --query '[VerifiedForSendingStatus,DkimAttributes.Status]' --output text
# True  SUCCESS
```

## 2. S3 bucket for raw messages

```bash
aws s3api create-bucket --bucket $BUCKET --region $REGION \
  $( [ "$REGION" != us-east-1 ] && echo --create-bucket-configuration LocationConstraint=$REGION )
aws s3api put-public-access-block --bucket $BUCKET \
  --public-access-block-configuration BlockPublicAcls=true,IgnorePublicAcls=true,BlockPublicPolicy=true,RestrictPublicBuckets=true
```

Let SES write to it:

```bash
aws s3api put-bucket-policy --bucket $BUCKET --policy '{
  "Version": "2012-10-17",
  "Statement": [{
    "Sid": "AllowSESPuts",
    "Effect": "Allow",
    "Principal": { "Service": "ses.amazonaws.com" },
    "Action": "s3:PutObject",
    "Resource": "arn:aws:s3:::'$BUCKET'/inbound/*",
    "Condition": { "StringEquals": { "AWS:SourceAccount": "'$ACCOUNT'" } }
  }]
}'
```

Expire objects after a couple of days. The package only reads them, so this is your retention policy:

```bash
aws s3api put-bucket-lifecycle-configuration --bucket $BUCKET --lifecycle-configuration '{
  "Rules": [{ "ID": "expire-inbound", "Status": "Enabled", "Filter": { "Prefix": "inbound/" }, "Expiration": { "Days": 2 } }]
}'
```

## 3. SNS topic

```bash
TOPIC_ARN=$(aws sns create-topic --name $TOPIC_NAME --region $REGION --query TopicArn --output text)
aws sns set-topic-attributes --region $REGION --topic-arn $TOPIC_ARN --attribute-name Policy --attribute-value '{
  "Version": "2012-10-17",
  "Statement": [{
    "Sid": "AllowSESPublish",
    "Effect": "Allow",
    "Principal": { "Service": "ses.amazonaws.com" },
    "Action": "sns:Publish",
    "Resource": "'$TOPIC_ARN'",
    "Condition": { "StringEquals": { "AWS:SourceAccount": "'$ACCOUNT'" } }
  }]
}'
echo $TOPIC_ARN
```

Do not subscribe the endpoint yet (step 6). Optionally ask SNS to sign with SHA-256, then set `INBOUND_MAIL_SES_REQUIRE_V2=true`:

```bash
aws sns set-topic-attributes --region $REGION --topic-arn $TOPIC_ARN --attribute-name SignatureVersion --attribute-value 2
```

## 4. SES receipt rule

```bash
aws ses create-receipt-rule-set --region $REGION --rule-set-name inbound
aws ses create-receipt-rule --region $REGION --rule-set-name inbound --rule '{
  "Name": "inbound-example",
  "Enabled": true,
  "TlsPolicy": "Optional",
  "ScanEnabled": true,
  "Recipients": ["inbound.example.com"],
  "Actions": [{
    "S3Action": { "BucketName": "'$BUCKET'", "ObjectKeyPrefix": "inbound/", "TopicArn": "'$TOPIC_ARN'" }
  }]
}'
aws ses set-active-receipt-rule-set --region $REGION --rule-set-name inbound
```

- The `TopicArn` inside the **S3 action** is required. That notification is the one that carries the object key, and the package ignores any notification without it.
- `ScanEnabled` produces the spam, virus and DMARC verdicts the package uses. Without it, `authenticated` is `null`.
- Only one rule set can be active per region. If one is already active, add the rule to it instead.
- Using the domain as the recipient matches every address under it, including plus-addressed ones.

## 5. IAM: let the application read the bucket

Add to the IAM user or role the application uses:

```json
{
  "Version": "2012-10-17",
  "Statement": [{
    "Effect": "Allow",
    "Action": "s3:GetObject",
    "Resource": "arn:aws:s3:::example-inbound-mail/inbound/*"
  }]
}
```

Read-only, one prefix. The package never deletes, which is why the lifecycle rule matters.

## 6. Configure the application, then subscribe

Set these and deploy first, so the endpoint exists to answer the subscription:

```
INBOUND_MAIL_SES_ROUTE=true
INBOUND_MAIL_SES_TOPICS=<TOPIC_ARN>
INBOUND_MAIL_SES_BUCKET=example-inbound-mail
INBOUND_MAIL_SES_REGION=<REGION>
```

`INBOUND_MAIL_SES_PREFIX` defaults to `inbound/`. Leave `INBOUND_MAIL_SES_KEY` and `INBOUND_MAIL_SES_SECRET` empty to use the AWS SDK's default credentials.

Then subscribe the endpoint:

```bash
aws sns subscribe --region $REGION --topic-arn $TOPIC_ARN \
  --protocol https --notification-endpoint https://example.com/inbound/email/ses/mail
```

- The package confirms the subscription itself. `aws sns list-subscriptions-by-topic` should show an ARN, not `PendingConfirmation`.
- Leave **raw message delivery** off: the package verifies the SNS signature, which needs the standard envelope.

## 7. Test

Send mail from an outside address to an address under the inbound domain. Your `HandlesInboundMail` class should be called once.

## If nothing arrives

0. **The sender gets `550 5.1.1 ... mailbox unavailable`:** the mail reached SES (so the MX record is fine) and SES refused it. The domain is not a verified identity (step 1b), or no active rule matches the recipient. Check `aws sesv2 get-email-identity --email-identity $DOMAIN` and `aws ses describe-active-receipt-rule-set`. A bounce means the sender will not retry; send the test again once it is fixed.
1. **S3:** did an object appear under `inbound/`? If not: the MX record, the rule set not being active, or the bucket policy.
2. **SNS:** is the subscription confirmed (`aws sns get-subscription-attributes` shows `PendingConfirmation: false`), and are there delivery failures?
3. **Application logs:** "Refused an inbound SNS message" means the signature, the topic ARN or an old timestamp; a 500 on the endpoint usually means the application cannot read S3 (step 5) or your handler threw.
4. **404 from the endpoint:** the route exists only when `INBOUND_MAIL_SES_ROUTE` is true, and it answers 404 until `INBOUND_MAIL_SES_TOPICS` is set. Clear a cached config after changing either.
