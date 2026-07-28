<?php

namespace App\Http\Controllers;

use App\Enums\NotificationCategory;
use App\Http\Resources\NotificationResource;
use App\Models\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Recipient-owned notification API. Every query is scoped to the authenticated
 * user (ownedBy) so a user can never read or mutate another user's records —
 * there is no admin/global read here. Resolve state is domain-controlled and
 * has no public mutation endpoint; only read-state is user-mutable.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;

        $query = AppNotification::query()->ownedBy($userId);

        if ($request->boolean('unread')) {
            $query->unread();
        }
        if ($request->boolean('unresolved')) {
            $query->unresolved();
        }
        $category = $request->string('category')->toString();
        if ($category !== '' && in_array($category, NotificationCategory::values(), true)) {
            $query->where('category', $category);
        }

        $perPage = min(50, max(5, (int) $request->integer('per_page', 20)));
        $page = $query->inboxOrdered()->paginate($perPage)->withQueryString();

        return NotificationResource::collection($page)
            ->additional(['meta' => [
                'unread_count' => AppNotification::query()->ownedBy($userId)->unread()->count(),
            ]])
            ->response();
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;

        return response()->json([
            'data' => [
                'unread' => AppNotification::query()->ownedBy($userId)->unread()->count(),
                'unresolved' => AppNotification::query()->ownedBy($userId)->unresolved()->count(),
            ],
        ]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $record = $this->ownedOrFail($request, $notification);
        if ($record->read_at === null) {
            $record->forceFill(['read_at' => Carbon::now(config('app.timezone'))])->save();
        }

        return (new NotificationResource($record))->response();
    }

    public function markUnread(Request $request, string $notification): JsonResponse
    {
        $record = $this->ownedOrFail($request, $notification);
        if ($record->read_at !== null) {
            $record->forceFill(['read_at' => null])->save();
        }

        return (new NotificationResource($record))->response();
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $now = Carbon::now(config('app.timezone'));

        // Marking read never touches resolve state or the domain workflow.
        $updated = AppNotification::query()
            ->ownedBy($userId)
            ->unread()
            ->update(['read_at' => $now]);

        return response()->json(['data' => ['marked_read' => $updated]]);
    }

    /** IDOR guard: resolve by public id ONLY within the caller's own records. */
    private function ownedOrFail(Request $request, string $publicId): AppNotification
    {
        return AppNotification::query()
            ->ownedBy((int) $request->user()->id)
            ->where('public_id', $publicId)
            ->firstOrFail();
    }
}
