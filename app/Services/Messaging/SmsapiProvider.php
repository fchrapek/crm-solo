<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Models\Account;
use App\Models\Integration;
use App\Models\OutboundMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** SMSAPI.pl with the account's own token (Integrations); in test mode SMSAPI checks the message and sends nothing. */
final class SmsapiProvider implements SmsProviderInterface
{
    private const string URL = 'https://api.smsapi.pl/sms.do';

    public static function connected(Account $account): ?Integration
    {
        $integration = $account->integrations()->where('provider', 'smsapi')->first();

        return $integration !== null && $integration->is_enabled && $integration->unreadableSecrets() === [] && $integration->isConfigured() ? $integration : null;
    }

    public function key(): string
    {
        return 'smsapi';
    }

    public function send(Account $account, string $to, string $body, string $reference): SmsResult
    {
        $integration = self::connected($account);
        if ($integration === null) {
            return new SmsResult(OutboundMessage::FAILED, error: 'SMSAPI is not connected in Integrations');
        }
        $test = (bool) config('services.smsapi.test');

        try {
            $response = Http::asForm()->withToken((string) $integration->api_key)->timeout(20)->post(self::URL, array_filter([
                'to' => mb_ltrim($to, '+'),
                'message' => $body,
                'encoding' => 'utf-8',
                'format' => 'json',
                'idx' => $reference,
                'test' => $test ? '1' : null,
            ]));
        } catch (ConnectionException $e) {
            return new SmsResult(OutboundMessage::FAILED, error: 'no answer from SMSAPI, the message may have gone out: '.$e->getMessage());
        }

        $id = $response->json('list.0.id');
        if ($response->successful() && $response->json('error') === null && is_string($id) && $id !== '') {
            return new SmsResult($test ? OutboundMessage::SIMULATED : OutboundMessage::SENT, $id);
        }

        $error = $response->json('error');

        return new SmsResult(OutboundMessage::FAILED, error: 'SMSAPI '.(is_scalar($error) ? $error : $response->status()).': '.($response->json('message') ?? mb_substr($response->body(), 0, 200)));
    }
}
