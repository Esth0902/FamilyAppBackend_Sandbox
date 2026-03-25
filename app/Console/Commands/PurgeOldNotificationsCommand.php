<?php

namespace App\Console\Commands;

use App\Models\UserNotification;
use Illuminate\Console\Command;

class PurgeOldNotificationsCommand extends Command
{
    protected $signature = 'notifications:purge-old
                            {--days=60 : Nombre de jours de conservation pour les notifications lues classiques}
                            {--important-days=180 : Nombre de jours de conservation pour les notifications importantes}';

    protected $description = 'Supprime les anciennes notifications lues selon une politique de rétention.';

    public function handle(): int
    {
        $days = max((int) $this->option('days'), 1);
        $importantDays = max((int) $this->option('important-days'), $days);

        $classicCutoff = now()->subDays($days);
        $importantCutoff = now()->subDays($importantDays);

        $importantTypes = [
            'household_invite',
            'household_invite_responded',
        ];

        $classicDeleted = UserNotification::query()
            ->whereNotNull('read_at')
            ->where('read_at', '<', $classicCutoff)
            ->where(function ($query) use ($importantTypes): void {
                $query->whereNull('type')
                    ->orWhereNotIn('type', $importantTypes);
            })
            ->delete();

        $importantDeleted = UserNotification::query()
            ->whereNotNull('read_at')
            ->where('read_at', '<', $importantCutoff)
            ->whereIn('type', $importantTypes)
            ->delete();

        $total = $classicDeleted + $importantDeleted;

        $this->info("Notifications supprimées : {$total}");
        $this->line(" - classiques : {$classicDeleted}");
        $this->line(" - importantes : {$importantDeleted}");

        return self::SUCCESS;
    }
}