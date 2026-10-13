<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Payments\PaymentProviderError;
use App\Modules\Payments\PaystackApi;

/** Stands in for api.paystack.co. Tests set what Paystack "says" and read what was asked. */
final class FakePaystack implements PaystackApi
{
    /** @var list<array{secret: string, email: string, amount: int, currency: string, reference: string, callback: string}> */
    public array $initialized = [];
    /** @var list<string> */
    public array $verified = [];
    public bool $acceptsKey = true;
    public bool $down = false;
    /** @var array{status: string, amount: ?int, currency: ?string} what verify() reports; null amount/currency echo the checkout */
    public array $verifyAnswer = ['status' => 'success', 'amount' => null, 'currency' => null];

    public function initialize(string $secret, string $email, int $amountMinor, string $currency, string $reference, string $callbackUrl, array $metadata): string
    {
        if ($this->down) {
            throw new PaymentProviderError('Could not reach Paystack.');
        }
        $this->initialized[] = ['secret' => $secret, 'email' => $email, 'amount' => $amountMinor, 'currency' => $currency, 'reference' => $reference, 'callback' => $callbackUrl];

        return 'https://checkout.paystack.com/abc123xyz';
    }

    public function verify(string $secret, string $reference): array
    {
        $this->verified[] = $reference;
        $started = null;
        foreach ($this->initialized as $i) {
            if ($i['reference'] === $reference) {
                $started = $i;
            }
        }

        return [
            'status' => $this->verifyAnswer['status'],
            'amount' => $this->verifyAnswer['amount'] ?? ($started['amount'] ?? 0),
            'currency' => $this->verifyAnswer['currency'] ?? ($started['currency'] ?? ''),
            'reference' => $reference,
            'id' => '4099260516',
            'channel' => 'card',
        ];
    }

    public function ping(string $secret): bool
    {
        if ($this->down) {
            throw new PaymentProviderError('Could not reach Paystack.');
        }

        return $this->acceptsKey;
    }
}
