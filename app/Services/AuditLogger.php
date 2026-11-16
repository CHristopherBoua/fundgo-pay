<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/** Journal d'audit : qui a fait quoi, sur quoi, avant / après. */
class AuditLogger
{
    public function log(string $action, Model|string $entity, ?string $entityId = null, ?array $before = null, ?array $after = null, ?string $actor = null): void
    {
        $request = app()->runningInConsole() ? null : request();

        AuditLog::create([
            'occurred_at' => now(),
            'actor' => $actor ?? $this->currentActor(),
            'action' => $action,
            'entity_type' => $entity instanceof Model ? class_basename($entity) : $entity,
            'entity_id' => $entity instanceof Model ? (string) $entity->getKey() : $entityId,
            'ip' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 500) : null,
            'before' => $before,
            'after' => $after,
        ]);
    }

    private function currentActor(): string
    {
        $id = auth()->id();

        return $id ? 'user:'.$id : 'system';
    }
}
