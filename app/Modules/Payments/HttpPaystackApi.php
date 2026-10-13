<?php

declare(strict_types=1);

namespace App\Modules\Payments;

/**
 * Talks to api.paystack.co over HTTPS with PHP's own streams (no curl
 * needed), verifying the certificate, with short timeouts. Only the fields
 * the platform uses are read from the answers.
 */
final class HttpPaystackApi implements PaystackApi
{
    private const BASE = 'https://api.paystack.co';

    public function __construct(private readonly int $timeoutSeconds = 15)
    {
    }

    public function initialize(string $secret, string $email, int $amountMinor, string $currency, string $reference, string $callbackUrl, array $metadata): string
    {
        $data = $this->call('POST', '/transaction/initialize', $secret, [
            'email' => $email,
            'amount' => (string) $amountMinor,
            'currency' => $currency,
            'reference' => $reference,
            'callback_url' => $callbackUrl,
            'metadata' => $metadata,
        ]);
        $url = is_array($data['data'] ?? null) ? ($data['data']['authorization_url'] ?? null) : null;
        if (!is_string($url) || preg_match('#^https://checkout\.paystack\.com/[A-Za-z0-9]+$#', $url) !== 1) {
            throw new PaymentProviderError('Paystack did not return a checkout page.');
        }

        return $url;
    }

    public function verify(string $secret, string $reference): array
    {
        $data = $this->call('GET', '/transaction/verify/' . rawurlencode($reference), $secret);
        $tx = is_array($data['data'] ?? null) ? $data['data'] : [];

        return [
            'status' => is_string($tx['status'] ?? null) ? $tx['status'] : 'unknown',
            'amount' => is_numeric($tx['amount'] ?? null) ? (int) $tx['amount'] : 0,
            'currency' => is_string($tx['currency'] ?? null) ? $tx['currency'] : '',
            'reference' => is_string($tx['reference'] ?? null) ? $tx['reference'] : '',
            'id' => is_scalar($tx['id'] ?? null) ? (string) $tx['id'] : '',
            'channel' => is_string($tx['channel'] ?? null) ? $tx['channel'] : null,
        ];
    }

    public function ping(string $secret): bool
    {
        try {
            $this->call('GET', '/transaction?perPage=1', $secret);

            return true;
        } catch (PaymentProviderError $e) {
            if ($e->getCode() === 401) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, string $secret, ?array $body = null): array
    {
        $headers = ['Authorization: Bearer ' . $secret, 'Accept: application/json'];
        $content = '';
        if ($body !== null) {
            $content = json_encode($body, JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: application/json';
        }
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $content,
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $raw = @file_get_contents(self::BASE . $path, false, $context);
        if ($raw === false) {
            throw new PaymentProviderError('Could not reach Paystack. Check the server can make outgoing HTTPS connections.');
        }
        $status = 0;
        // PHP 8.4 adds http_get_last_response_headers(); 8.3 fills $http_response_header.
        $responseHeaders = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : $http_response_header;
        foreach ($responseHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }
        $data = json_decode($raw, true);
        if ($status === 401) {
            throw new PaymentProviderError('Paystack refused the secret key.', 401);
        }
        if ($status < 200 || $status >= 300 || !is_array($data) || ($data['status'] ?? false) !== true) {
            $message = is_array($data) && is_string($data['message'] ?? null) ? mb_substr($data['message'], 0, 200) : 'unexpected answer';
            throw new PaymentProviderError('Paystack said: ' . $message, $status);
        }

        return $data;
    }
}
