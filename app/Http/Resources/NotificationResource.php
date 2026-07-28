<?php

namespace App\Http\Resources;

use App\Models\AppNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Allowlisted, runtime-stable notification projection for the frontend. Only
 * these fields are ever exposed: no internal ids, storage paths, checksums,
 * actor ids, or private metadata. The deep link is a stable route KEY plus the
 * public subject id — the FE registry resolves it to an in-app route that
 * re-authorizes the current user.
 *
 * @mixin AppNotification
 */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'event_type' => $this->event_type,
            'category' => $this->category->value,
            'priority' => $this->priority->value,
            'title' => $this->title,
            'body' => $this->body,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_public_id,
            'action' => $this->action_route_key ? [
                'route_key' => $this->action_route_key,
                'label' => $this->action_label,
            ] : null,
            'is_read' => $this->read_at !== null,
            'is_resolved' => $this->resolved_at !== null,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'read_at' => $this->read_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'schema_version' => (int) $this->schema_version,
        ];
    }
}
