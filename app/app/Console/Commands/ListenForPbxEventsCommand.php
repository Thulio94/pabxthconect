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
            try {
                $socket = fsockopen($config['host'], $config['port'], $errno, $error, $config['timeout']);
                if (! $socket) throw new \RuntimeException("AMI indisponível: {$error} ({$errno})");
                stream_set_timeout($socket, 30);
                $this->readBlock($socket); // AMI banner
                $this->send($socket, ['Action' => 'Login', 'Username' => $config['username'], 'Secret' => $config['secret'], 'Events' => 'on']);
                $login = $this->readResponse($socket);
                if (($login['Response'] ?? null) !== 'Success') throw new \RuntimeException('AMI recusou o listener de eventos.');

                while (! feof($socket)) {
                    $event = $this->readBlock($socket);
                    if (($event['Event'] ?? null) !== null) $processor->process($event);

                    $metadata = stream_get_meta_data($socket);
                    if ($metadata['timed_out'] ?? false) {
                        throw new \RuntimeException('Conexão AMI ficou inativa e será renovada.');
                    }
                }
                fclose($socket);
            } catch (Throwable $exception) {
                report($exception);
                $this->warn('Reconectando ao AMI em 5 segundos.');
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

    private function readResponse($socket): array
    {
        do {
            $block = $this->readBlock($socket);
        } while (! feof($socket) && ! isset($block['Response']));
        return $block;
    }
}
