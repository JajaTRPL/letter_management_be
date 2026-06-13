<?php

namespace App\Console\Commands;

use App\Services\LetterRetentionOptions;
use App\Services\LetterRetentionService;
use Illuminate\Console\Command;

class LettersRetentionCommand extends Command
{
    protected $signature = 'letters:retention
        {--execute : Execute eligible retention actions. Dry-run is the default.}
        {--letter-type= : Limit to one canonical letter type.}
        {--application-id= : Limit to one application id.}
        {--category= : Limit to supporting_document, intermediate_artifact, final_official_pdf, or archived_final_pdf.}
        {--batch= : Maximum number of actions to plan or execute.}
        {--manifest : Write a private JSON manifest under storage/app/private/reports/retention.}';

    protected $description = 'Plan or execute generic completed-letter retention actions.';

    public function handle(LetterRetentionService $retention): int
    {
        $category = $this->option('category');
        if ($category !== null && $category !== '' && !in_array($category, LetterRetentionService::CATEGORIES, true)) {
            $this->error('Invalid category. Use one of: ' . implode(', ', LetterRetentionService::CATEGORIES));

            return self::FAILURE;
        }

        $applicationId = $this->option('application-id');
        $batch = $this->option('batch');

        $result = $retention->run(new LetterRetentionOptions(
            execute: (bool) $this->option('execute'),
            letterType: $this->stringOption('letter-type'),
            applicationId: $applicationId !== null && $applicationId !== '' ? (int) $applicationId : null,
            category: $category !== null && $category !== '' ? (string) $category : null,
            batch: $batch !== null && $batch !== '' ? max(1, (int) $batch) : (int) config('letter_retention.batch_size', 100),
            manifest: (bool) $this->option('manifest'),
        ));

        if (!$result->schemaReady) {
            $this->warn('Retention schema is not installed; no retention actions were planned.');

            return $result->execute ? self::FAILURE : self::SUCCESS;
        }

        $this->info(sprintf(
            'letters:retention %s completed: %d action(s).',
            $result->execute ? 'execute' : 'dry-run',
            $result->total(),
        ));

        foreach ($result->countsByStatus() as $status => $count) {
            $this->line("{$status}: {$count}");
        }

        if ($result->manifestPath) {
            $this->line('Private manifest written.');
        }

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
