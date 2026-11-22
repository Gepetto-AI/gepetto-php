<?php

declare(strict_types=1);

namespace Gepetto;

/**
 * Webhooks: with `webhook` in a tool's parameters, the call returns at once and Gepetto sends the finished job
 * to that URL, as a signed POST.
 *
 *     $job = Webhook::verify(file_get_contents('php://input'), $_SERVER['HTTP_GEPETTO_SIGNATURE'] ?? null);
 */
final class Webhook
{
    /**
     * Checks the `Gepetto-Signature` header and returns the job of the webhook.
     *
     * @param string      $body       The raw body of the request, before any decoding.
     * @param string|null $signature  The `Gepetto-Signature` header.
     * @param string|null $secret     Your webhook secret (`webhook_secret` of account(), or `npx @gepetto/cli secret`);
     *                                by default GEPETTO_WEBHOOK_SECRET. Test and live keys have different secrets.
     * @param int         $tolerance  Seconds: an older signature is refused.
     * @throws GepettoException `invalid_signature` when the webhook does not come from Gepetto.
     */
    public static function verify(string $body, ?string $signature, ?string $secret = null, int $tolerance = 300): Job
    {
        $secret ??= Gepetto::env('GEPETTO_WEBHOOK_SECRET');
        if ($secret === null || $secret === '') {
            throw new GepettoException(
                'invalid_parameter',
                'No webhook secret: pass it to Webhook::verify(), or set GEPETTO_WEBHOOK_SECRET. '
                . 'It is webhook_secret in $gepetto->account(), or `npx @gepetto/cli secret`.',
            );
        }
        if ($signature === null || $signature === '') {
            throw self::invalid('no Gepetto-Signature header');
        }

        // The header is "t=<unix time>,v1=<hex>"
        $parts = [];
        foreach (explode(',', $signature) as $part) {
            [$name, $value] = array_pad(explode('=', $part, 2), 2, '');
            $parts[trim($name)] = trim($value);
        }
        $time = $parts['t'] ?? '';
        $given = $parts['v1'] ?? '';
        if (!ctype_digit($time) || $given === '') {
            throw self::invalid('malformed header');
        }
        if (abs(time() - (int) $time) > $tolerance) {
            throw self::invalid('timestamp too old');
        }

        $expected = hash_hmac('sha256', $time . '.' . $body, $secret);
        if (!hash_equals($expected, $given)) {
            throw self::invalid('signature mismatch (wrong secret, or the body was modified)');
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw self::invalid('the body is not JSON');
        }
        return Job::fromArray($data);
    }

    private static function invalid(string $why): GepettoException
    {
        return new GepettoException('invalid_signature', "Invalid webhook signature: {$why}.");
    }
}
