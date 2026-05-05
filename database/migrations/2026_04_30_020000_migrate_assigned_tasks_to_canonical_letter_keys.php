<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    private const UP_MAP = [
        'aktif' => 'surat-keterangan-aktif',
        'magang' => 'surat-pengantar-magang',
        'beasiswa' => 'surat-permohonan-beasiswa',
        'Beasiswa' => 'surat-permohonan-beasiswa',
        'Surat Beasiswa' => 'surat-permohonan-beasiswa',
        'Surat Permohonan Beasiswa' => 'surat-permohonan-beasiswa',
        'luar_negeri' => 'proses-luar-negeri',
    ];

    private const DOWN_MAP = [
        'surat-keterangan-aktif' => 'aktif',
        'surat-pengantar-magang' => 'magang',
        'surat-permohonan-beasiswa' => 'beasiswa',
        'proses-luar-negeri' => 'luar_negeri',
    ];

    private const CANONICAL_KEYS = [
        'surat-keterangan-aktif',
        'surat-pengantar-magang',
        'surat-permohonan-beasiswa',
        'proses-luar-negeri',
    ];

    public function up(): void
    {
        $this->migrateAssignedTasks(self::UP_MAP, self::CANONICAL_KEYS);
    }

    public function down(): void
    {
        $this->migrateAssignedTasks(self::DOWN_MAP, array_values(self::UP_MAP));
    }

    /**
     * @param array<string, string> $map
     * @param array<int, string> $knownValues
     */
    private function migrateAssignedTasks(array $map, array $knownValues): void
    {
        DB::table('users')
            ->whereNotNull('assigned_tasks')
            ->orderBy('id')
            ->select('id', 'assigned_tasks')
            ->chunk(100, function ($users) use ($map, $knownValues) {
                foreach ($users as $user) {
                    $tasks = $this->decodeAssignedTasks($user->assigned_tasks);
                    if ($tasks === []) {
                        continue;
                    }

                    $unknown = [];
                    $mappedTasks = [];

                    foreach ($tasks as $task) {
                        if (is_string($task) && array_key_exists($task, $map)) {
                            $mappedTasks[] = $map[$task];
                            continue;
                        }

                        if (is_string($task) && !in_array($task, $knownValues, true)) {
                            $unknown[] = $task;
                        }

                        $mappedTasks[] = $task;
                    }

                    $mappedTasks = $this->uniqueValues($mappedTasks);

                    if ($unknown !== []) {
                        Log::warning('Unknown assigned_tasks values preserved during letter key migration.', [
                            'user_id' => $user->id,
                            'unknown_assigned_tasks' => $this->uniqueValues($unknown),
                        ]);
                    }

                    if ($mappedTasks !== $tasks) {
                        DB::table('users')
                            ->where('id', $user->id)
                            ->update([
                                'assigned_tasks' => json_encode(array_values($mappedTasks)),
                            ]);
                    }
                }
            });
    }

    /**
     * @return array<int, mixed>
     */
    private function decodeAssignedTasks(mixed $assignedTasks): array
    {
        if (is_array($assignedTasks)) {
            return $assignedTasks;
        }

        if (!is_string($assignedTasks) || trim($assignedTasks) === '') {
            return [];
        }

        $decoded = json_decode($assignedTasks, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<int, mixed> $values
     * @return array<int, mixed>
     */
    private function uniqueValues(array $values): array
    {
        $seen = [];
        $unique = [];

        foreach ($values as $value) {
            $identifier = is_scalar($value) || $value === null
                ? gettype($value) . ':' . (string) $value
                : gettype($value) . ':' . json_encode($value);

            if (isset($seen[$identifier])) {
                continue;
            }

            $seen[$identifier] = true;
            $unique[] = $value;
        }

        return $unique;
    }
};
