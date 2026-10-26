<?php

declare(strict_types=1);

namespace App\Modules\Payments;

/** The Paystack calls the platform makes. Tests replace this with a fake. */
interface PaystackApi
{
    /**
     * Starts a checkout; returns the page to send the learner to.
     *
     * @param array<string, scalar> $metadata
     * @throws PaymentProviderError
     */
    public function initialize(string $secret, string $email, int $amountMinor, string $currency, string $reference, string $callbackUrl, array $metadata): string;

    /**
     * The transaction as Paystack records it: status, amount, currency, id, channel.
     *
     * @return array{status: string, amount: int, currency: string, reference: string, id: string, channel: ?string}
     * @throws PaymentProviderError
     */
    public function verify(string $secret, string $reference): array;

    /** True when Paystack accepts the key. @throws PaymentProviderError when Paystack cannot be reached */
    public function ping(string $secret): bool;

    /**
     * Asks Paystack to pay back all or part of a transaction (ADR 0032). Paystack
     * usually answers "pending" and settles it later with a refund webhook.
     *
     * @return array{id: string, status: string}
     * @throws PaymentProviderError
     */
    public function refund(string $secret, string $reference, int $amountMinor, string $reason): array;
}
