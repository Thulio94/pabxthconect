<?php

namespace App\Services\Pbx;

use App\Models\Extension;

class TurnCredentialFactory
{
    /** @return array<int, array<string, string|array<int, string>>> */
    public function forExtension(Extension $extension): array
    {
        $host = trim((string) config('pbx.turn.host'));
        $secret = (string) config('pbx.turn.auth_secret');

        if ($host === '' || $secret === '') {
            return [];
        }

        $username = now()->addSeconds((int) config('pbx.turn.ttl_seconds'))->timestamp.':ext-'.$extension->id;
        $urls = [
            'turn:'.$host.':'.((int) config('pbx.turn.port')).'?transport=udp',
            'turn:'.$host.':'.((int) config('pbx.turn.port')).'?transport=tcp',
        ];
        if (config('pbx.turn.tls_enabled')) {
            $urls[] = 'turns:'.$host.':'.((int) config('pbx.turn.tls_port')).'?transport=tcp';
        }

        return [[
            'urls' => $urls,
            'username' => $username,
            'credential' => base64_encode(hash_hmac('sha1', $username, $secret, true)),
        ]];
    }
}
