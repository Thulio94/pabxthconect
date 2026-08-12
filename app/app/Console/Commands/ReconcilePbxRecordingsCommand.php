<?php

namespace App\Console\Commands;

use App\Services\Pbx\RecordingReconciler;
use Illuminate\Console\Command;

class ReconcilePbxRecordingsCommand extends Command
{
    protected $signature = 'pbx:recordings:sync {--limit=500}';
    protected $description = 'Reconcile MixMonitor WAV files with recording metadata.';

    public function handle(RecordingReconciler $reconciler): int
    {
        $count = $reconciler->reconcile(max(1, (int) $this->option('limit')));
        $this->info("{$count} gravação(ões) recuperada(s).");
        return self::SUCCESS;
    }
}
