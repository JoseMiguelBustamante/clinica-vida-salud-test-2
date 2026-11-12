<?php

declare(strict_types=1);

namespace App\Modules\Appointments;

interface StripeCheckoutGateway
{
    public function isConfigured(): bool;

    /**
     * @param  array{amount_minor: int, currency: string, product_name: string, success_url: string, cancel_url: string, expires_at: int, metadata: array<string, string>}  $input
     * @return array{id: string, url: string}
     */
    public function createCheckoutSession(array $input): array;

    public function expireCheckoutSession(string $sessionId): void;

    /**
     * @return array{id: string, url: ?string, status: ?string, payment_status: ?string}
     */
    public function getCheckoutSession(string $sessionId): array;

    public function refundPaymentIntent(string $paymentIntentId, string $idempotencyKey): void;

    /**
     * @return array{type: string, session: array{id: string, payment_status?: string, amount_total?: int, payment_intent?: string|null}}
     */
    public function parseWebhook(string $payload, string $signatureHeader): array;
}
