<?php

namespace App\Http\Controllers\RoomManagement;

use App\Http\Controllers\Concerns\BuildsRoomManagementPayloads;
use App\Http\Controllers\Controller;
use App\Http\Requests\RoomManagement\StoreRoomTemplateRequest;
use App\Models\Room;
use App\Models\RoomDocumentTemplate;
use App\Services\RoomPermissionResolver;
use App\Services\RoomTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RoomTemplateController extends Controller
{
    use BuildsRoomManagementPayloads;

    public function __construct(
        private RoomPermissionResolver $resolver,
        private RoomTemplateService $templates,
    ) {
    }

    public function index(Request $request, Room $room): JsonResponse
    {
        abort_unless($this->resolver->canReadRoomManagement($request->user(), $room), 404);

        return response()->json([
            'message' => 'Daftar template berhasil diambil',
            'data' => $this->templates->templatesForRoom($room)
                ->map(fn (RoomDocumentTemplate $template) => $this->roomTemplatePayload($template, $room))
                ->all(),
        ]);
    }

    public function store(StoreRoomTemplateRequest $request, Room $room): JsonResponse
    {
        abort_unless($this->resolver->canManageRoomTemplates($request->user(), $room), 404);

        try {
            $template = $this->templates->upload(
                $room,
                $request->file('template'),
                $request->user(),
                $request->validated('notes'),
                $request->ip(),
            );
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Template berhasil diunggah dan diaktifkan',
            'data' => $this->roomTemplatePayload($template, $room),
        ], 201);
    }

    public function activate(Request $request, Room $room, RoomDocumentTemplate $template): JsonResponse
    {
        $this->authorizeTemplate($request, $room, $template);

        $this->templates->activate($room, $template, $request->user(), $request->ip());

        return response()->json([
            'message' => 'Template berhasil diaktifkan',
            'data' => $this->roomTemplatePayload($template->fresh(), $room),
        ]);
    }

    public function deactivate(Request $request, Room $room, RoomDocumentTemplate $template): JsonResponse
    {
        $this->authorizeTemplate($request, $room, $template);

        $this->templates->deactivate($room, $template, $request->user(), $request->ip());

        return response()->json([
            'message' => 'Template berhasil dinonaktifkan',
            'data' => $this->roomTemplatePayload($template->fresh(), $room),
        ]);
    }

    public function download(Request $request, Room $room, RoomDocumentTemplate $template): StreamedResponse
    {
        $this->authorizeTemplate($request, $room, $template);

        return $this->templates->downloadResponse($template);
    }

    private function authorizeTemplate(Request $request, Room $room, RoomDocumentTemplate $template): void
    {
        abort_unless($this->resolver->canManageRoomTemplates($request->user(), $room), 404);

        // The template must belong to this room's scope chain.
        $belongs = $this->templates->templatesForRoom($room)->contains('id', $template->id);
        abort_unless($belongs, 404);
    }
}
