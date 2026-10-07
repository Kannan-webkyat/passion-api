<?php

namespace App\Support;

use App\Models\AiosellIntegration;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Outbound AioSell channel manager (https://live.aiosell.com/api/v2/cm).
 * Calls are skipped until the connection is on and credentials are saved.
 */
final class AiosellClient
{
    public static function baseUrl(): string
    {
        return rtrim((string) config('services.aiosell.base_url', 'https://live.aiosell.com/api/v2/cm'), '/');
    }

    public static function integration(): AiosellIntegration
    {
        return AiosellIntegration::current();
    }

    public static function ready(): bool
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('aiosell_integrations')) {
            return false;
        }

        return self::integration()->ready();
    }

    public static function webhookAuthorized(?string $header): bool
    {
        $integration = self::integration();
        $user = (string) $integration->username;
        $pass = (string) $integration->password;
        if ($user === '' || $pass === '' || ! is_string($header) || ! str_starts_with($header, 'Basic ')) {
            return false;
        }
        $decoded = base64_decode(substr($header, 6), true);
        if ($decoded === false || ! str_contains($decoded, ':')) {
            return false;
        }
        [$givenUser, $givenPass] = explode(':', $decoded, 2);

        return hash_equals($user, $givenUser) && hash_equals($pass, $givenPass);
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @param  array<string, mixed>  $query
     * @return array{ok: bool, status: int, json: array<string, mixed>, message: string}
     */
    public static function send(string $method, string $path, ?array $json = null, array $query = []): array
    {
        $integration = self::integration();
        if (! $integration->ready()) {
            return ['ok' => false, 'status' => 0, 'json' => [], 'message' => 'AioSell is not connected.'];
        }

        $url = self::baseUrl().'/'.ltrim($path, '/');
        $attempt = 0;
        $last = ['ok' => false, 'status' => 0, 'json' => [], 'message' => 'AioSell is not connected.'];

        while ($attempt < 2) {
            $attempt++;
            try {
                $response = Http::withBasicAuth((string) $integration->username, (string) $integration->password)
                    ->acceptJson()
                    ->timeout(25)
                    ->send(strtoupper($method), $url, array_filter([
                        'json' => $json,
                        'query' => $query !== [] ? $query : null,
                    ]));
            } catch (Throwable $e) {
                return ['ok' => false, 'status' => 0, 'json' => [], 'message' => $e->getMessage()];
            }

            $last = self::decode($response);
            if ($last['status'] !== 429 || $attempt === 2) {
                return $last;
            }
            usleep(200000);
        }

        return $last;
    }

    /**
     * @return array{ok: bool, status: int, json: array<string, mixed>, message: string}
     */
    public static function propertyDetails(): array
    {
        $integration = self::integration();

        return self::send('GET', '/property_details/'.rawurlencode((string) $integration->hotel_code), null, [
            'partnerId' => (string) $integration->partner_id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{ok: bool, status: int, json: array<string, mixed>, message: string}
     */
    public static function pushInventory(array $body): array
    {
        return self::send('POST', '/update/'.rawurlencode((string) self::integration()->partner_id), $body);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{ok: bool, status: int, json: array<string, mixed>, message: string}
     */
    public static function pushRates(array $body): array
    {
        return self::send('POST', '/update-rates/'.rawurlencode((string) self::integration()->partner_id), $body);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{ok: bool, status: int, json: array<string, mixed>, message: string}
     */
    public static function markNoShow(array $body): array
    {
        return self::send('POST', '/marknoshow/'.rawurlencode((string) self::integration()->partner_id), $body);
    }

    /**
     * @return array{ok: bool, status: int, json: array<string, mixed>, message: string}
     */
    public static function fetch(string $type, string $startDate, string $endDate): array
    {
        return self::send('POST', '/data/'.rawurlencode((string) self::integration()->partner_id), [
            'type' => $type,
            'hotelCode' => (string) self::integration()->hotel_code,
            'startDate' => $startDate,
            'endDate' => $endDate,
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{ok: bool, status: int, json: array<string, mixed>, message: string}
     */
    public static function channelMultiplier(array $body): array
    {
        return self::send('POST', '/channel_multiplier/'.rawurlencode((string) self::integration()->partner_id), $body);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{ok: bool, status: int, json: array<string, mixed>, message: string}
     */
    public static function messageReply(array $body): array
    {
        return self::send('POST', '/message-reply/'.rawurlencode((string) self::integration()->partner_id), $body);
    }

    /**
     * @return array{ok: bool, status: int, json: array<string, mixed>, message: string}
     */
    private static function decode(Response $response): array
    {
        $json = $response->json();
        if (! is_array($json)) {
            $json = [];
        }

        $message = '';
        if ($json !== [] && ! array_is_list($json)) {
            $message = (string) ($json['message'] ?? '');
        }
        if ($message === '' && ! $response->successful()) {
            $message = 'AioSell returned HTTP '.$response->status().'.';
        }

        $ok = $response->successful();
        if ($json !== [] && ! array_is_list($json) && array_key_exists('success', $json) && $json['success'] === false) {
            $ok = false;
        }

        return [
            'ok' => $ok,
            'status' => $response->status(),
            'json' => $json,
            'message' => $message,
        ];
    }
}
