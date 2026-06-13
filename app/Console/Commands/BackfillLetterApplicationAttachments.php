<?php

namespace App\Console\Commands;

use App\Enums\LetterAttachmentBackfillClassification as State;
use App\Services\LetterAttachmentBackfillPlanItem;
use App\Services\LetterAttachmentBackfillService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * D2C backfill command for legacy supporting documents.
 *
 * SAFETY: the default run is a pure DRY-RUN — it inserts no rows and copies,
 * moves, or deletes no files. The destructive EXECUTE path requires BOTH
 * --execute AND --confirm=BACKFILL; supplying --execute alone is refused.
 */
class BackfillLetterApplicationAttachments extends Command
{
    protected $signature = 'letter-attachments:backfill
        {--execute : Perform the copy + registry insert (otherwise dry-run only)}
        {--confirm= : Must equal BACKFILL to allow --execute}
        {--letter-type= : Restrict to a single canonical letter type}
        {--application-id= : Restrict to a single application id}
        {--output= : Write a JSON plan report to this private-storage relative path}';

    protected $description = 'Plan (dry-run) or execute the legacy supporting-document backfill into the private attachment registry.';

    public function handle(LetterAttachmentBackfillService $service): int
    {
        $execute = (bool) $this->option('execute');
        $confirm = $this->option('confirm');

        if ($execute && $confirm !== 'BACKFILL') {
            $this->error('Refusing to execute: pass --confirm=BACKFILL to run the destructive backfill.');

            return self::FAILURE;
        }

        $filters = [
            'letter_type' => $this->option('letter-type') ?: null,
            'application_id' => $this->option('application-id') ? (int) $this->option('application-id') : null,
        ];

        if ($execute) {
            $this->line('<options=bold;fg=red>MODE: EXECUTE</>');
            $this->line('Rows will be inserted and files copied into private storage.');
            $items = $service->execute($filters);
        } else {
            $this->line('<options=bold;fg=green>MODE: DRY-RUN</>');
            $this->line('No DB rows will be inserted.');
            $this->line('No files will be copied, moved, or deleted.');
            $items = $service->plan($filters);
        }

        $counts = $this->summarize($items);
        $this->renderSummary($counts, count($items));

        if ($output = $this->option('output')) {
            $this->writeJsonReport($items, $execute ? 'EXECUTE' : 'DRY-RUN', (string) $output);
        }

        // A dry-run that surfaces blockers still exits 0 (it is informational);
        // the operator reads the summary. Execute returns failure if any
        // actionable row failed to land as a verified match.
        if ($execute && ($counts[State::REGISTRY_CONFLICT->value] ?? 0) > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param list<LetterAttachmentBackfillPlanItem> $items
     * @return array<string, int>
     */
    private function summarize(array $items): array
    {
        $counts = array_fill_keys(State::values(), 0);
        foreach ($items as $item) {
            $counts[$item->classification->value]++;
        }

        return $counts;
    }

    /**
     * @param array<string, int> $counts
     */
    private function renderSummary(array $counts, int $total): void
    {
        $rows = [];
        foreach ($counts as $state => $count) {
            if ($count > 0) {
                $rows[] = [$state, $count];
            }
        }
        $rows[] = ['TOTAL', $total];

        $this->newLine();
        $this->table(['Classification', 'Count'], $rows);

        $blockers = ($counts[State::MARKER_WITHOUT_REGISTRY_BLOCKER->value] ?? 0)
            + ($counts[State::REGISTRY_CONFLICT->value] ?? 0)
            + ($counts[State::DESTINATION_CONFLICT->value] ?? 0)
            + ($counts[State::SOURCE_MIME_INVALID->value] ?? 0)
            + ($counts[State::SOURCE_PATH_UNSAFE->value] ?? 0);
        if ($blockers > 0) {
            $this->warn("{$blockers} blocker row(s) must be resolved before an execute run.");
        }
    }

    /**
     * @param list<LetterAttachmentBackfillPlanItem> $items
     */
    private function writeJsonReport(array $items, string $mode, string $relativePath): void
    {
        $payload = [
            'mode' => $mode,
            'generated_at' => now()->toIso8601String(),
            'items' => array_map(static fn (LetterAttachmentBackfillPlanItem $i): array => $i->toReportRow(), $items),
        ];

        $safePath = $this->normalizeReportPath($relativePath);
        if ($safePath === null) {
            $this->error('Refusing to write report: --output path is unsafe.');

            return;
        }

        // Reports live on the PRIVATE local disk only; never public, never a URL.
        Storage::disk('local')->put($safePath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info("Plan report written to private storage (local disk): {$safePath}");
    }

    /**
     * Keep operator --output paths inside the private local disk. Accepts either
     * a disk-relative path or the convenience "storage/app/(private/)?..." form,
     * and rejects traversal / absolute paths.
     */
    private function normalizeReportPath(string $raw): ?string
    {
        $path = ltrim(str_replace('\\', '/', trim($raw)), '/');

        foreach (['storage/app/private/', 'storage/app/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $path = substr($path, strlen($prefix));
                break;
            }
        }

        $segments = array_filter(explode('/', $path), 'strlen');
        if ($path === '' || in_array('..', $segments, true) || in_array('.', $segments, true)) {
            return null;
        }

        return $path;
    }
}
