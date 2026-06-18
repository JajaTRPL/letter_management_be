<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LetterRetentionManifestWriter
{
    public function newPath(Carbon $now): string
    {
        return sprintf(
            'reports/retention/%s-%s.json',
            $now->format('Ymd-His'),
            Str::uuid(),
        );
    }

    public function write(LetterRetentionRunResult $result, string $path): void
    {
        $payload = [
            'generated_at' => Carbon::now()->toIso8601String(),
            'mode' => $result->execute ? 'execute' : 'dry-run',
            'schema_ready' => $result->schemaReady,
            'error_code' => $result->errorCode,
            'counts_by_status' => $result->countsByStatus(),
            'actions' => array_map(
                fn (LetterRetentionActionResult $action): array => $action->toManifestArray(),
                $result->actions,
            ),
        ];

        Storage::disk('local')->put(
            $path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }
}
