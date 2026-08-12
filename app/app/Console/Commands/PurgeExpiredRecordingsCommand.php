<?php

namespace App\Console\Commands;

use App\Models\Recording;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PurgeExpiredRecordingsCommand extends Command
{
    protected $signature = 'pbx:recordings:purge';
    protected $description = 'Delete recordings whose tenant retention period has expired.';

    public function handle(): int
    {
        $recordings = Recording::query()->whereNull('deleted_at')->whereNotNull('delete_after')->where('delete_after', '<=', now())->get();
        foreach ($recordings as $recording) {
            Storage::disk($recording->storage_disk)->delete($recording->path);
            $recording->update(['deleted_at' => now()]);
        }
        $this->info("{$recordings->count()} gravações removidas.");
        return self::SUCCESS;
    }
}
