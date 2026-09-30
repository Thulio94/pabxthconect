<?php

namespace App\Console\Commands;

use App\Services\PhoneLicenseLeaseReaper;
use Illuminate\Console\Command;

class ReapStalePhoneLicenseLeasesCommand extends Command
{
    protected $signature = 'pbx:licenses:reap-stale';

    protected $description = 'Release phone licenses when the browser heartbeat has expired.';

    public function handle(PhoneLicenseLeaseReaper $reaper): int
    {
        $reaped = $reaper->reap();
        $this->info("{$reaped} licença(s) liberada(s) por sessão expirada.");

        return self::SUCCESS;
    }
}
