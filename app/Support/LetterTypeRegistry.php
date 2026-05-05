<?php

namespace App\Support;

class LetterTypeRegistry
{
    /**
     * @return array<int, array{key: string, label: string, category: string, legacy_keys: array<int, string>}>
     */
    public static function all(): array
    {
        return array_values(config('surat.types', []));
    }

    /**
     * Shape returned to clients. `name` is kept as a compatibility alias for older UI code.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forApi(): array
    {
        return array_map(function (array $type): array {
            $type['legacy_keys'] = array_values($type['legacy_keys'] ?? []);
            $type['name'] = $type['label'];

            return $type;
        }, self::all());
    }

    /**
     * @return array<int, string>
     */
    public static function canonicalKeys(): array
    {
        return array_values(array_map(
            fn (array $type): string => $type['key'],
            self::all()
        ));
    }

    /**
     * @return array<int, string>
     */
    public static function assignmentKeysFor(string $canonicalKey, bool $includeLegacy = false): array
    {
        $type = self::find($canonicalKey);
        if (!$type) {
            return [$canonicalKey];
        }

        $keys = [$type['key']];
        if ($includeLegacy) {
            $keys = array_merge($keys, $type['legacy_keys'] ?? []);
        }

        return array_values(array_unique($keys));
    }

    public static function canonicalize(?string $key): ?string
    {
        if ($key === null) {
            return null;
        }

        $normalizedKey = strtolower(trim($key));
        foreach (self::all() as $type) {
            if (strtolower($type['key']) === $normalizedKey) {
                return $type['key'];
            }

            foreach ($type['legacy_keys'] ?? [] as $legacyKey) {
                if (strtolower($legacyKey) === $normalizedKey) {
                    return $type['key'];
                }
            }
        }

        return null;
    }

    public static function labelFor(string $canonicalKey): string
    {
        $type = self::find($canonicalKey);

        return $type['label'] ?? $canonicalKey;
    }

    /**
     * @return array{tasks: array<int, mixed>, unknown: array<int, mixed>}
     */
    public static function canonicalizeAssignedTasks(array $tasks, bool $preserveUnknown = true): array
    {
        $canonicalTasks = [];
        $unknownTasks = [];

        foreach ($tasks as $task) {
            $canonicalTask = is_string($task) ? self::canonicalize($task) : null;

            if ($canonicalTask) {
                $canonicalTasks[] = $canonicalTask;
                continue;
            }

            $unknownTasks[] = $task;
            if ($preserveUnknown) {
                $canonicalTasks[] = $task;
            }
        }

        return [
            'tasks' => self::uniqueValues($canonicalTasks),
            'unknown' => self::uniqueValues($unknownTasks),
        ];
    }

    private static function find(string $canonicalKey): ?array
    {
        foreach (self::all() as $type) {
            if (($type['key'] ?? null) === $canonicalKey) {
                return $type;
            }
        }

        return null;
    }

    private static function uniqueValues(array $values): array
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
}
