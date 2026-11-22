<?php

declare(strict_types=1);

namespace Gepetto;

/**
 * Sends one HTTP request with curl. The client uses it unless you pass your own `transport`
 * (a callable with the same signature, for tests or another HTTP library).
 */
final class CurlTransport
{
    /**
     * @param list<string> $headers  Lines such as "Authorization: Bearer ...".
     * @param float        $timeout  Seconds.
     * @return array{status: int, body: string}
     * @throws TransportException When the API could not be reached, or did not answer in time.
     */
    public function __invoke(string $method, string $url, array $headers, ?string $body, float $timeout): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new TransportException('curl_init failed');
        }

        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => (int) ceil(min($timeout, 30) * 1000),
            CURLOPT_TIMEOUT_MS => (int) ceil($timeout * 1000),
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $text = curl_exec($curl);
        if ($text === false || !is_string($text)) {
            $timedOut = curl_errno($curl) === CURLE_OPERATION_TIMEDOUT;
            throw new TransportException(curl_error($curl) ?: 'request failed', $timedOut);
        }

        return ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'body' => $text];
    }
}
