<?php

namespace App\Services\Pbx;

use App\Models\Extension;
use RuntimeException;

class AmiClient
{
    /**
     * @return array<int>|null IDs de ramais com um canal PJSIP ativo. Retorna
     *                         null quando o AMI nÃ£o puder ser consultado para evitar falso encerramento.
     */
    public function activeExtensionIds(): ?array
    {
        $config = config('pbx.ami');
        if (! $config['username'] || ! $config['secret']) {
            return null;
        }

        $socket = null;
        try {
            $socket = @fsockopen($config['host'], $config['port'], $errno, $error, $config['timeout']);
            if (! $socket) {
                return null;
            }
            stream_set_timeout($socket, $config['timeout']);
            $this->send($socket, ['Action' => 'Login', 'Username' => $config['username'], 'Secret' => $config['secret'], 'Events' => 'off']);
            if (! str_contains($this->readResponse($socket), 'Response: Success')) {
                return null;
            }

            $this->send($socket, ['Action' => 'CoreShowChannels']);
            $sipUsernames = [];
            while (! feof($socket)) {
                $block = $this->readHeaders($socket);
                if (($block['Event'] ?? null) === 'CoreShowChannelsComplete') {
                    break;
                }
                if (($block['Event'] ?? null) !== 'CoreShowChannel') {
                    continue;
                }
                if (preg_match('/PJSIP\\/(t\\d+-e\\d+)-/', $block['Channel'] ?? '', $matches)) {
                    $sipUsernames[] = $matches[1];
                }
            }

            return $this->extensionIdsForSipUsernames($sipUsernames);
        } catch (\Throwable) {
            return null;
        } finally {
            if (is_resource($socket)) {
                $this->send($socket, ['Action' => 'Logoff']);
                fclose($socket);
            }
        }
    }

    /** @return array<int> */
    public function extensionIdsForSipUsernames(array $sipUsernames): array
    {
        return Extension::query()
            ->whereIn('sip_username', array_values(array_unique($sipUsernames)))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function command(string $command): void
    {
        $config = config('pbx.ami');

        if (! $config['username'] || ! $config['secret']) {
            throw new RuntimeException('AMI não está configurado.');
        }

        $socket = @fsockopen($config['host'], $config['port'], $errno, $error, $config['timeout']);
        if (! $socket) {
            throw new RuntimeException("Não foi possível conectar ao AMI: {$error} ({$errno}).");
        }

        stream_set_timeout($socket, $config['timeout']);
        $this->send($socket, ['Action' => 'Login', 'Username' => $config['username'], 'Secret' => $config['secret']]);
        $this->assertSuccess($this->readResponse($socket), 'autenticar no AMI');

        $this->send($socket, ['Action' => 'Command', 'Command' => $command]);
        $this->assertSuccess($this->readResponse($socket), "executar {$command}");

        $this->send($socket, ['Action' => 'Logoff']);
        fclose($socket);
    }

    private function send($socket, array $headers): void
    {
        foreach ($headers as $key => $value) {
            fwrite($socket, "{$key}: {$value}\r\n");
        }
        fwrite($socket, "\r\n");
    }

    private function readResponse($socket): string
    {
        while (! feof($socket)) {
            $block = '';
            while (! feof($socket)) {
                $line = fgets($socket);
                if ($line === false || trim($line) === '') {
                    break;
                }
                $block .= $line;
            }
            if (str_contains($block, 'Response:')) {
                return $block;
            }
        }

        return '';
    }

    private function readHeaders($socket): array
    {
        $headers = [];
        while (! feof($socket)) {
            $line = fgets($socket);
            if ($line === false || trim($line) === '') {
                break;
            }
            [$key, $value] = array_pad(explode(':', trim($line), 2), 2, null);
            if ($key && $value !== null) {
                $headers[$key] = ltrim($value);
            }
        }

        return $headers;
    }

    private function assertSuccess(string $response, string $action): void
    {
        if (! str_contains($response, 'Response: Success') && ! str_contains($response, 'Response: Follows')) {
            throw new RuntimeException("Falha ao {$action}: ".trim($response));
        }
    }
}
