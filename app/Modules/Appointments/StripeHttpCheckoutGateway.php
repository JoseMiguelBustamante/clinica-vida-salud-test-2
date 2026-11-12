<?php

declare(strict_types=1);

namespace App\Modules\Appointments;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class StripeHttpCheckoutGateway implements StripeCheckoutGateway
{
    public function isConfigured(): bool
    {
        $secret = (string) config('stripe.secret_key', '');

        return str_starts_with($secret, 'sk_');
    }

    public function createCheckoutSession(array $input): array
    {
        try {
            $response = Http::asForm()
                ->timeout(20)
                ->withToken((string) config('stripe.secret_key'))
                ->post('https://api.stripe.com/v1/checkout/sessions', $this->sessionPayload($input));
        } catch (Throwable $exception) {
            Log::error('[StripeHttpCheckoutGateway - createCheckoutSession] '.$exception::class);
            throw new PaymentGatewayUnavailable;
        }

        if (! $response->successful()) {
            Log::error('[StripeHttpCheckoutGateway - createCheckoutSession] gateway rejected request');
            throw new PaymentGatewayUnavailable;
        }

        $id = (string) $response->json('id');
        $url = (string) $response->json('url');
        if ($id === '' || $url === '') {
            throw new PaymentGatewayUnavailable;
        }

        return ['id' => $id, 'url' => $url];
    }

    public function expireCheckoutSession(string $sessionId): void
    {
        try {
            $response = Http::asForm()
                ->timeout(15)
                ->withToken((string) config('stripe.secret_key'))
                ->post('https://api.stripe.com/v1/checkout/sessions/'.rawurlencode($sessionId).'/expire');
        } catch (Throwable $exception) {
            Log::warning('[StripeHttpCheckoutGateway - expireCheckoutSession] '.$exception::class);

            return;
        }

        if (! $response->successful() && $response->status() !== 400) {
            Log::warning('[StripeHttpCheckoutGateway - expireCheckoutSession] unable to expire session');
        }
    }

    public function getCheckoutSession(string $sessionId): array
    {
        try {
            $response = Http::timeout(15)
                ->withToken((string) config('stripe.secret_key'))
                ->get('https://api.stripe.com/v1/checkout/sessions/'.rawurlencode($sessionId));
        } catch (Throwable $exception) {
            Log::warning('[StripeHttpCheckoutGateway - getCheckoutSession] '.$exception::class);
            throw new PaymentGatewayUnavailable;
        }

        if (! $response->successful()) {
            Log::warning('[StripeHttpCheckoutGateway - getCheckoutSession] unable to retrieve session');
            throw new PaymentGatewayUnavailable;
        }

        $url = $response->json('url');

        return [
            'id' => (string) $response->json('id'),
            'url' => is_string($url) && $url !== '' ? $url : null,
            'status' => ($status = $response->json('status')) !== null ? (string) $status : null,
            'payment_status' => ($paymentStatus = $response->json('payment_status')) !== null ? (string) $paymentStatus : null,
        ];
    }

    public function refundPaymentIntent(string $paymentIntentId, string $idempotencyKey): void
    {
        $response = Http::asForm()
            ->withToken((string) config('stripe.secret_key'))
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('https://api.stripe.com/v1/refunds', [
                'payment_intent' => $paymentIntentId,
            ]);

        if (! $response->successful()) {
            Log::warning('[StripeHttpCheckoutGateway - refundPaymentIntent] unable to refund');
        }
    }

    public function parseWebhook(string $payload, string $signatureHeader): array
    {
        $secret = (string) config('stripe.webhook_secret', '');
        if ($secret === '') {
            throw new PaymentGatewayUnavailable;
        }

        $this->assertValidSignature($payload, $signatureHeader, $secret);

        /** @var array{type?: string, data?: array{object?: array<string, mixed>}} $event */
        $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $type = (string) ($event['type'] ?? '');
        $object = $event['data']['object'] ?? [];
        if ($type === '' || ! is_array($object) || ! isset($object['id'])) {
            throw new InvalidPaymentWebhook;
        }

        return [
            'type' => $type,
            'session' => [
                'id' => (string) $object['id'],
                'payment_status' => isset($object['payment_status']) ? (string) $object['payment_status'] : null,
                'amount_total' => isset($object['amount_total']) ? (int) $object['amount_total'] : null,
                'payment_intent' => isset($object['payment_intent'])
                    ? (is_string($object['payment_intent']) ? $object['payment_intent'] : null)
                    : null,
            ],
        ];
    }

    /**
     * @param  array{amount_minor: int, currency: string, product_name: string, success_url: string, cancel_url: string, expires_at: int, metadata: array<string, string>}  $input
     * @return array<string, int|string>
     */
    private function sessionPayload(array $input): array
    {
        $payload = [
            'mode' => 'payment',
            'success_url' => $input['success_url'],
            'cancel_url' => $input['cancel_url'],
            'expires_at' => $input['expires_at'],
            'line_items[0][quantity]' => 1,
            'line_items[0][price_data][currency]' => $input['currency'],
            'line_items[0][price_data][unit_amount]' => $input['amount_minor'],
            'line_items[0][price_data][product_data][name]' => $input['product_name'],
        ];

        foreach ($input['metadata'] as $key => $value) {
            $payload['metadata['.$key.']'] = $value;
        }

        return $payload;
    }

    private function assertValidSignature(string $payload, string $header, string $secret): void
    {
        /** @var array<string, list<string>> $parts */
        $parts = [];
        foreach (explode(',', $header) as $item) {
            $segments = explode('=', trim($item), 2);
            if (count($segments) !== 2) {
                continue;
            }
            [$key, $value] = $segments;
            $parts[$key][] = $value;
        }

        $timestamp = $parts['t'][0] ?? null;
        $signatures = $parts['v1'] ?? [];
        if ($timestamp === null || $signatures === []) {
            throw new InvalidPaymentWebhook;
        }

        if (abs(time() - (int) $timestamp) > 300) {
            throw new InvalidPaymentWebhook;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return;
            }
        }

        throw new InvalidPaymentWebhook;
    }
}
