<?php

namespace App\Actions\Tasks;

use App\Models\TaskInstance;
use Illuminate\Support\Facades\DB;

class ToggleTaskStatusAction
{
    private const STATUS_DONE = 'réalisée';

    public function execute(TaskInstance $instance, string $status): TaskInstance
    {
        return DB::transaction(function () use ($instance, $status): TaskInstance {
            $updates = [
                'status' => $status,
            ];

            if ($status === self::STATUS_DONE) {
                $updates['completed_at'] = $instance->completed_at ?? now();
            } else {
                $updates['completed_at'] = null;
                $updates['validated_by_parent'] = false;
            }

            $instance->update($updates);

            return $instance->refresh();
        });
    }
}
