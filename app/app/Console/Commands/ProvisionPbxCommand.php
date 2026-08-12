<?php

namespace App\Console\Commands;

use App\Services\Pbx\AmiClient;
use App\Services\Pbx\PbxConfigGenerator;
use Illuminate\Console\Command;
use Throwable;

class ProvisionPbxCommand extends Command
{
    protected $signature = 'pbx:provision {--no-reload : Generate files without reloading Asterisk}';

    protected $description = 'Generate PBX PJSIP and dialplan files from the SaaS database.';

    public function handle(PbxConfigGenerator $generator, AmiClient $ami): int
    {
        $generator->generate();
        $this->info('Arquivos de provisionamento gerados.');

        if ($this->option('no-reload')) {
            return self::SUCCESS;
        }

        try {
            $ami->command('core reload');
            $this->info('Asterisk recarregado.');
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Os arquivos foram gerados, mas o Asterisk não foi recarregado: '.$exception->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
