<?php

namespace App\Console\Commands;

use App\Models\AiUsageLog;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class CleanupAiUsageLogsCommand extends Command
{
    protected $signature = 'ai-usage:cleanup
                            {--months=6 : Nombre de mois de conservation}
                            {--mode=delete : delete|archive}
                            {--chunk=1000 : Taille des lots de traitement}
                            {--dry-run : Simule sans modifier les données}';

    protected $description = 'Archive ou supprime les logs IA plus anciens que la période de conservation.';

    public function handle(): int
    {
        $months = max((int) $this->option('months'), 1);
        $chunkSize = max((int) $this->option('chunk'), 100);
        $mode = mb_strtolower(trim((string) $this->option('mode')));
        $dryRun = (bool) $this->option('dry-run');

        if (!in_array($mode, ['delete', 'archive'], true)) {
            $this->error("Mode invalide: {$mode}. Valeurs autorisées: delete, archive.");
            return self::INVALID;
        }

        $cutoff = now()->subMonthsNoOverflow($months);
        $query = AiUsageLog::query()
            ->where('created_at', '<', $cutoff)
            ->orderBy('id');

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('Aucun log IA à nettoyer.');
            return self::SUCCESS;
        }

        $this->line("Logs ciblés: {$total}");
        $this->line('Date de coupure: ' . $cutoff->toDateTimeString());
        $this->line("Mode: {$mode}" . ($dryRun ? ' (dry-run)' : ''));

        if ($dryRun) {
            return self::SUCCESS;
        }

        if ($mode === 'archive') {
            return $this->archiveAndDelete($query, $chunkSize);
        }

        return $this->deleteOnly($query, $chunkSize);
    }

    private function deleteOnly($query, int $chunkSize): int
    {
        $deleted = 0;

        $query->chunkById($chunkSize, function (Collection $logs) use (&$deleted): void {
            $ids = $logs->pluck('id')->all();
            $deleted += AiUsageLog::query()->whereIn('id', $ids)->delete();
        });

        $this->info("Logs IA supprimés: {$deleted}");
        return self::SUCCESS;
    }

    private function archiveAndDelete($query, int $chunkSize): int
    {
        $archiveDir = storage_path('app/ai-usage-archives');
        if (!is_dir($archiveDir) && !mkdir($archiveDir, 0775, true) && !is_dir($archiveDir)) {
            $this->error('Impossible de créer le dossier d\'archive: ' . $archiveDir);
            return self::FAILURE;
        }

        $archivePath = $archiveDir . DIRECTORY_SEPARATOR . 'ai_usage_logs_' . now()->format('Ymd_His') . '.ndjson';
        $handle = fopen($archivePath, 'ab');
        if ($handle === false) {
            $this->error('Impossible d\'ouvrir le fichier d\'archive: ' . $archivePath);
            return self::FAILURE;
        }

        $archived = 0;
        $deleted = 0;

        try {
            $query->chunkById($chunkSize, function (Collection $logs) use (&$archived, &$deleted, $handle): void {
                foreach ($logs as $log) {
                    $line = json_encode($log->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if (!is_string($line) || fwrite($handle, $line . PHP_EOL) === false) {
                        throw new \RuntimeException('Échec d\'écriture pendant l\'archivage.');
                    }

                    $archived++;
                }

                $ids = $logs->pluck('id')->all();
                $deleted += AiUsageLog::query()->whereIn('id', $ids)->delete();
            });
        } catch (\Throwable $exception) {
            fclose($handle);
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        fclose($handle);

        $this->info("Logs IA archivés: {$archived}");
        $this->info("Logs IA supprimés après archivage: {$deleted}");
        $this->info('Fichier archive: ' . $archivePath);

        return self::SUCCESS;
    }
}
