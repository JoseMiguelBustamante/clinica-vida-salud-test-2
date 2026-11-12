<?php

namespace Tests\Unit;

use App\Modules\Appointments\InvalidPaymentWebhook;
use App\Modules\Appointments\StripeHttpCheckoutGateway;
use Tests\TestCase;

class StripeHttpCheckoutGatewayTest extends TestCase
{
    public function test_is_configured_requires_a_secret_key_prefix(): void
    {
        config(['stripe.secret_key' => null]);
        $this->assertFalse((new StripeHttpCheckoutGateway)->isConfigured());

        config(['stripe.secret_key' => 'sk_test_example']);
        $this->assertTrue((new StripeHttpCheckoutGateway)->isConfigured());
    }

    public function test_webhook_signature_must_match_hmac(): void
    {
        config(['stripe.webhook_secret' => 'whsec_test']);
        $payload = '{"type":"checkout.session.completed","data":{"object":{"id":"cs_1","payment_status":"paid","amount_total":15000}}}';
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test');

        $event = (new StripeHttpCheckoutGateway)->parseWebhook($payload, 't='.$timestamp.',v1='.$signature);

        $this->assertSame('checkout.session.completed', $event['type']);
        $this->assertSame('cs_1', $event['session']['id']);
        $this->assertSame(15000, $event['session']['amount_total']);
    }

    public function test_invalid_webhook_signature_is_rejected(): void
    {
        config(['stripe.webhook_secret' => 'whsec_test']);
        $this->expectException(InvalidPaymentWebhook::class);
        (new StripeHttpCheckoutGateway)->parseWebhook('{}', 't='.time().',v1=deadbeef');
    }
}
