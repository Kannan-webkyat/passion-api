<?php

namespace App\Support;

use App\Models\DoorloomIntegration;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Outbound Doorloom Brand Integration API (v2026-09).
 * Calls are skipped until an API key is saved and the integration is enabled.
 */
final class DoorloomClient
{
    public static function baseUrl(): string
    {
        return rtrim((string) config('services.doorloom.base_url', 'https://doorloom.com/api/integrations/v1'), '/');
    }

    public static function integration(): DoorloomIntegration
    {
        return DoorloomIntegration::current();
    }

    public static function ready(): bool
    {
        return self::integration()->ready();
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     * @return array{ok: bool, status: int, json: array<string, mixed>, message: string}
     */
    public static function send(string $method, string $path, ?array $json = null, array $query = [], array $headers = []): array
    {
        $integration = self::integration();
        if (! $integration->ready()) {
            return ['ok' => false, 'status' => 0, 'json' => [], 'message' => 'Doorloom is not connected.'];
        }

        $url = self::baseUrl().'/'.ltrim($path, '/');

        try {
            $pending = Http::withToken((string) $integration->api_key)
                ->acceptJson()
                ->timeout(20)
                ->withHeaders($headers);
            $response = $pending->send(strtoupper($method), $url, array_filter([
                'json' => $json,
                'query' => $query !== [] ? $query : null,
            ]));
        } catch (Throwable $e) {
            return ['ok' => false, 'status' => 0, 'json' => [], 'message' => $e->getMessage()];
        }

        return self::decode($response);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{ok: bool, status: int, json: array<string, mixed>, message: string}
     */
    public static function createProperty(array $body, string $idempotencyKey): array
    {
        return self::send('POST', '/properties', $body, [], [
            'Idempotency-Key' => $idempotencyKey,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $properties
     * @return array{ok: bool, status: int, json: array<string, mixed>, message: string}
     */
    public static function bulkCreateProperties(array $properties, string $idempotencyKey): array
    {
        return self::send('POST', '/properties/bulk', ['properties' => $properties], [], [
            'Idempotency-Key' => $idempotencyKey,
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{ok: bool, status: int, json: array<string, mixed>, message: string}
     */
    public static function updateProperty(int $doorloomId, array $body): array
    {
        return self::send('PUT', '/properties/'.$doorloomId, $body);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{ok: bool, status: int, json: array<string, mixed>, message: string}
     */
    public static function createBooking(array $body, string $idempotencyKey): array
    {
        return self::send('POST', '/bookings', $body, [], [
            'Idempotency-Key' => $idempotencyKey,
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{ok: bool, status: int, json: array<string, mixed>, message: string}
     */
    public static function updateBooking(int $doorloomId, array $body, ?int $revision): array
    {
        $headers = [];
        if ($revision !== null) {
            $headers['If-Match'] = (string) $revision;
        }

        return self::send('PATCH', '/bookings/'.$doorloomId, $body, [], $headers);
    }

    /**
     * @return array{ok: bool, status: int, json: array<string, mixed>, message: string}
     */
    public static function getBooking(int $doorloomId): array
    {
        return self::send('GET', '/bookings/'.$doorloomId);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{ok: bool, status: int, json: array<string, mixed>, message: string}
     */
    public static function cancelBooking(int $doorloomId, array $body = []): array
    {
        return self::send('POST', '/bookings/'.$doorloomId.'/cancel', $body);
    }

    /**
     * @return array{ok: bool, status: int, json: array<string, mixed>, message: string}
     */
    public static function events(int $afterSequence, int $limit = 200): array
    {
        return self::send('GET', '/events', null, [
            'after_sequence' => $afterSequence,
            'limit' => $limit,
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{ok: bool, status: int, json: array<string, mixed>, message: string}
     */
    public static function fullSync(array $body = []): array
    {
        return self::send('POST', '/sync', $body);
    }

    /**
     * @return array{ok: bool, status: int, json: array<string, mixed>, message: string}
     */
    public static function me(): array
    {
        return self::send('GET', '/me');
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

        $message = (string) ($json['message'] ?? '');
        if ($message === '' && ! $response->successful()) {
            $message = 'Doorloom returned HTTP '.$response->status().'.';
        }

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'json' => $json,
            'message' => $message,
        ];
    }
}
