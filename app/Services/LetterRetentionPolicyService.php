<?php

namespace App\Services;

use App\Models\LetterRetentionPolicy;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class LetterRetentionPolicyService
{
    public const GLOBAL_SCOPE = 'global';

    /**
     * @return array<string, int>
     */
    public function defaults(): array
    {
        return [
            'supporting_document_retention_days' => max(0, (int) config('letter_retention.supporting_document_retention_days', 14)),
            'intermediate_artifact_retention_days' => max(0, (int) config('letter_retention.intermediate_artifact_retention_days', 14)),
            'final_pdf_active_days' => max(0, (int) config('letter_retention.final_pdf_active_days', 30)),
            'archive_retention_days' => max(0, (int) config('letter_retention.archive.retention_days', 365)),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function current(): array
    {
        $defaults = $this->defaults();
        if (!$this->schemaReady()) {
            return $defaults;
        }

        $policy = LetterRetentionPolicy::query()
            ->where('scope', self::GLOBAL_SCOPE)
            ->first();

        if (!$policy) {
            return $defaults;
        }

        return [
            'supporting_document_retention_days' => (int) $policy->supporting_document_retention_days,
            'intermediate_artifact_retention_days' => (int) $policy->intermediate_artifact_retention_days,
            'final_pdf_active_days' => (int) $policy->final_pdf_active_days,
            'archive_retention_days' => (int) $policy->archive_retention_days,
        ];
    }

    public function value(string $key, int $default): int
    {
        return max(0, (int) ($this->current()[$key] ?? $default));
    }

    /**
     * @param array<string, int> $values
     */
    public function update(array $values, ?int $updatedBy): LetterRetentionPolicy
    {
        if (!$this->schemaReady()) {
            throw new RuntimeException('letter_retention_policy_schema_not_ready');
        }

        $payload = array_merge($this->current(), $values);
        $payload['updated_by'] = $updatedBy;

        return LetterRetentionPolicy::query()->updateOrCreate(
            ['scope' => self::GLOBAL_SCOPE],
            $payload,
        );
    }

    public function schemaReady(): bool
    {
        return Schema::hasTable('letter_retention_policies')
            && Schema::hasColumn('letter_retention_policies', 'supporting_document_retention_days')
            && Schema::hasColumn('letter_retention_policies', 'intermediate_artifact_retention_days')
            && Schema::hasColumn('letter_retention_policies', 'final_pdf_active_days')
            && Schema::hasColumn('letter_retention_policies', 'archive_retention_days');
    }
}
