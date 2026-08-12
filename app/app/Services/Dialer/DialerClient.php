<?php

namespace App\Services\Dialer;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Throwable;

class DialerClient
{
    public function findMatchingTenant(Collection $tenants, string $extension, string $password): array
    {
        $configuredTenants = $tenants->filter(fn (Tenant $tenant) => filled($tenant->internal_token))->values();

        if ($configuredTenants->isEmpty()) {
            throw new DialerException('integration_not_configured');
        }

        $url = rtrim((string) config('services.dialer.base_url'), '/').'/api/agent/sip-credentials';
        $responses = Http::pool(function (Pool $pool) use ($configuredTenants, $extension, $url): void {
            foreach ($configuredTenants as $tenant) {
                $pool->as((string) $tenant->getKey())
                    ->acceptJson()
                    ->withToken($tenant->internal_token)
                    ->connectTimeout(3)
                    ->timeout(8)
                    ->get($url, ['ramal' => $extension]);
            }
        }, 8);

        $matches = [];
        $receivedResponse = false;

        foreach ($configuredTenants as $tenant) {
            $response = $responses[(string) $tenant->getKey()] ?? null;

            if (! $response instanceof Response) {
                continue;
            }

            $receivedResponse = true;

            if (! $response->successful()) {
                continue;
            }

            try {
                $credentials = $this->validateCredentials($response->json());
            } catch (DialerException) {
                continue;
            }

            if (hash_equals((string) $credentials['sip_pass'], $password)) {
                $matches[] = ['tenant' => $tenant, 'credentials' => $credentials];
            }
        }

        if (! $receivedResponse) {
            throw new DialerException('unavailable');
        }

        if (count($matches) !== 1) {
            throw new DialerException(count($matches) > 1 ? 'ambiguous_credentials' : 'credentials_not_found');
        }

        return $matches[0];
    }

    public function sipCredentials(Tenant $tenant, string $extension): array
    {
        if (blank($tenant->internal_token)) {
            throw new DialerException('integration_not_configured');
        }

        try {
            $response = Http::baseUrl(rtrim((string) config('services.dialer.base_url'), '/'))
                ->acceptJson()
                ->withToken($tenant->internal_token)
                ->connectTimeout(3)
                ->timeout(8)
                ->get('/api/agent/sip-credentials', ['ramal' => $extension]);
        } catch (Throwable) {
            throw new DialerException('unavailable');
        }

        if (! $response->successful()) {
            throw new DialerException(match ($response->status()) {
                401 => 'invalid_token',
                404 => 'extension_unavailable',
                422 => 'invalid_extension',
                502 => 'webrtc_unavailable',
                default => 'unavailable',
            });
        }

        return $this->validateCredentials($response->json());
    }

    private function validateCredentials(mixed $credentials): array
    {
        $validator = Validator::make(is_array($credentials) ? $credentials : [], [
            'sip_user' => ['required', 'string', 'max:255'],
            'sip_pass' => ['required', 'string', 'max:255'],
            'sip_host' => ['required', 'string', 'max:500'],
            'sip_ws_uri' => ['required', 'string', 'max:1000', 'regex:/^wss:\/\/[^\s]+$/i'],
            'context' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            throw new DialerException('invalid_response');
        }

        return $validator->validated();
    }
}
