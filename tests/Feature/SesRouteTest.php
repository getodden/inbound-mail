<?php

it('registers no route until the endpoint is turned on', function (): void {
    $this->post('/inbound/email/ses/mail')->assertNotFound();
    expect(app('router')->has('inbound-mail.ses'))->toBeFalse();
});
