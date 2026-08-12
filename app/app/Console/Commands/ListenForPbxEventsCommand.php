<?php

namespace App\Console\Commands;

use App\Services\Pbx\AmiEventProcessor;
use Illuminate\Console\Command;
use Throwable;

class ListenForPbxEventsCommand extends Command
{
    protected $signature = 'pbx:events';
    protected $description = 'Listen to Asterisk AMI events and persist PBX call history.';

    public function handle(AmiEventProcessor $processor): int
    {
        $config = config('pbx.ami');
        $this->info('Ouvindo eventos AMI do PBX.');

        while (true) {
            $socket = null;
            try {
                $socket = fsockopen($config['host'], $config['port'], $errno, $error, $config['timeout']);
                if (! $socket) throw new \RuntimeException("AMI indisponível: {$error} ({$errno})");

                stream_set_timeout($socket, 20);
                $this->readBlock($socket); // AMI banner
                $this->send($socket, [
                    'Action' => 'Login',
                    'Username' => $config['username'],
                    'Secret' => $config['secret'],
                    'Events' => 'on',
                ]);
                $login = $this->readResponse($socket, $processor);
                if (($login['Response'] ?? null) !== 'Success') {
                    throw new \RuntimeException('AMI recusou o listener de eventos: '.($login['Message'] ?? 'sem detalhes'));
                }
                $this->info('Listener AMI conectado e autenticado.');

                while (! feof($socket)) {
                    $event = $this->readBlock($socket);
                    if (($event['Event'] ?? null) !== null) $processor->process($event);

                    $metadata = stream_get_meta_data($socket);
                    if ($metadata['timed_out'] ?? false) {
                        // Mantém a conexão viva e processa eventos que eventualmente
                        // cheguem antes da resposta do heartbeat.
                        $this->send($socket, ['Action' => 'Ping', 'ActionID' => 'thconect-heartbeat']);
                        $pong = $this->readResponse($socket, $processor);
                        if (($pong['Response'] ?? null) !== 'Success' || ($pong['Ping'] ?? null) !== 'Pong') {
                            throw new \RuntimeException('O Asterisk não respondeu ao heartbeat AMI.');
                        }
                    }
                }

                throw new \RuntimeException('O Asterisk encerrou a conexão AMI.');
            } catch (Throwable $exception) {
                report($exception);
                $this->error('AMI desconectado: '.$exception->getMessage());
                $this->warn('Reconectando ao AMI em 5 segundos.');
                if (is_resource($socket)) fclose($socket);
                sleep(5);
            }
        }
    }

    private function send($socket, array $headers): void
    {
        foreach ($headers as $key => $value) fwrite($socket, "{$key}: {$value}\r\n");
        fwrite($socket, "\r\n");
    }

    private function readBlock($socket): array
    {
        $event = [];
        while (! feof($socket)) {
            $line = fgets($socket);
            if ($line === false || trim($line) === '') break;
            [$key, $value] = array_pad(explode(':', trim($line), 2), 2, null);
            if ($key && $value !== null) $event[$key] = ltrim($value);
        }
        return $event;
    }

    private function readResponse($socket, AmiEventProcessor $processor): array
    {
        do {
            $block = $this->readBlock($socket);
            if (($block['Event'] ?? null) !== null) $processor->process($block);
        } while (! feof($socket) && ! isset($block['Response']));

        return $block;
    }
}
