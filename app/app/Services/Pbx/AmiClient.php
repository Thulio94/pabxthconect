<?php

namespace App\Services\Pbx;

use RuntimeException;

class AmiClient
{
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

    private function assertSuccess(string $response, string $action): void
    {
        if (! str_contains($response, 'Response: Success') && ! str_contains($response, 'Response: Follows')) {
            throw new RuntimeException("Falha ao {$action}: ".trim($response));
        }
    }
}
