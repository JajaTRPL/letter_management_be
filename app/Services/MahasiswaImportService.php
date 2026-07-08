<?php

namespace App\Services;

use App\Enums\UserStatus;
use App\Helpers\DateHelper;
use App\Helpers\NimHelper;
use App\Models\ImportBatch;
use App\Models\ImportBatchRow;
use App\Models\MahasiswaProfile;
use App\Models\StudyProgram;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Verified Mahasiswa import pipeline: parsing (CSV/XLSX), row validation,
 * conflict planning, and transactional commit.
 *
 * One service on purpose — the template contract (v2), the validator, and
 * the write rules must never drift apart.
 *
 * Conflict policy (see plan()):
 *   - new email + new NIM                       → create
 *   - existing mahasiswa without NIM            → update (merge)
 *   - existing mahasiswa, identical data        → skip (idempotent)
 *   - existing mahasiswa, NIM/prodi differ      → fail, unless override
 *   - existing mahasiswa, only name/birth diff  → skip with note, unless override
 *   - email owned by non-mahasiswa              → fail
 *   - NIM owned by another account              → fail
 *   - duplicate email/NIM within the file       → fail
 */
class MahasiswaImportService
{
    public const TEMPLATE_VERSION = 'v2';
    public const HEADERS = ['name', 'email', 'nim', 'study_program_code', 'tanggal_lahir'];
    public const DATA_SHEET = 'Data Mahasiswa';
    public const MAX_ROWS = 5000;
    public const ALLOWED_EMAIL_DOMAINS = ['mail.ugm.ac.id', 'ugm.ac.id'];

    /** Reserved code used by template sample rows; always fails validation. */
    public const SAMPLE_PROGRAM_CODE = 'CONTOH';

    private const FORMULA_PREFIXES = ['=', '+', '@'];

    /**
     * Parse an uploaded CSV or XLSX file into raw template rows.
     *
     * @return array{rows: list<array<string, string>>, source_format: string}
     *
     * @throws MahasiswaImportException on structural problems (headers,
     *         missing sheet, row cap) with a UI-safe Indonesian message.
     */
    public function parse(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if ($extension === 'xlsx') {
            return ['rows' => $this->parseXlsx($file), 'source_format' => 'xlsx'];
        }

        return ['rows' => $this->parseCsv($file), 'source_format' => 'csv'];
    }

    /**
     * Build the row-by-row import plan without touching users/profiles.
     *
     * @param  list<array<string, string>> $rows
     * @return array{rows: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function plan(array $rows, bool $overrideExistingActive): array
    {
        $programIdsByCode = StudyProgram::runtimeVisible()->pluck('id', 'code')->all();
        $programCodesById = array_flip($programIdsByCode);

        // Normalize every row first so bulk lookups use final key forms.
        $normalized = array_map(function (array $row) {
            $rawTanggal = trim((string) ($row['tanggal_lahir'] ?? ''));

            return [
                'name' => trim((string) ($row['name'] ?? '')),
                'email' => strtolower(trim((string) ($row['email'] ?? ''))),
                'nim' => NimHelper::normalize($row['nim'] ?? null),
                'study_program_code' => strtoupper(trim((string) ($row['study_program_code'] ?? ''))),
                'raw_tanggal_lahir' => $rawTanggal,
                'tanggal_lahir' => DateHelper::normalizeDate($rawTanggal !== '' ? $rawTanggal : null),
            ];
        }, $rows);

        $emails = array_values(array_unique(array_filter(array_column($normalized, 'email'))));
        $nims = array_values(array_unique(array_filter(array_column($normalized, 'nim'))));

        $usersByEmail = User::with('mahasiswaProfile')
            ->whereIn(DB::raw('LOWER(email)'), $emails)
            ->get()
            ->keyBy(fn (User $user) => strtolower($user->email));

        $profilesByNim = MahasiswaProfile::whereIn('nim', $nims)->get()->keyBy('nim');

        $planRows = [];
        $seenEmails = [];
        $seenNims = [];
        $summary = ['total' => 0, 'valid' => 0, 'invalid' => 0, 'create' => 0, 'update' => 0, 'skip' => 0, 'fail' => 0];

        foreach ($normalized as $index => $row) {
            $rowNumber = $index + 2; // header is spreadsheet row 1

            [$errors, $programId] = $this->validateRow($row, $programIdsByCode);

            // In-file duplicates
            if ($row['email'] !== '' && isset($seenEmails[$row['email']])) {
                $errors[] = 'Email duplikat dalam file.';
            }
            if ($row['nim'] !== null && isset($seenNims[$row['nim']])) {
                $errors[] = 'NIM duplikat dalam file.';
            }
            if ($row['email'] !== '') {
                $seenEmails[$row['email']] = true;
            }
            if ($row['nim'] !== null) {
                $seenNims[$row['nim']] = true;
            }

            $entry = [
                'row_number' => $rowNumber,
                'data' => [
                    'name' => $row['name'],
                    'email' => $row['email'],
                    'nim' => (string) $row['nim'],
                    'study_program_code' => $row['study_program_code'],
                    'tanggal_lahir' => (string) $row['tanggal_lahir'],
                ],
                'action' => ImportBatchRow::ACTION_FAIL,
                'status' => ImportBatchRow::STATUS_INVALID,
                'errors' => [],
                'changes' => null,
                'note' => null,
                'user_id' => null,
                'study_program_id' => $programId,
            ];

            if ($errors === []) {
                $existingUser = $usersByEmail[$row['email']] ?? null;
                $nimOwner = $profilesByNim[$row['nim']] ?? null;

                $this->resolveConflict($entry, $row, $existingUser, $nimOwner, $programId, $programCodesById, $overrideExistingActive, $errors);
            }

            if ($errors !== []) {
                $entry['action'] = ImportBatchRow::ACTION_FAIL;
                $entry['status'] = ImportBatchRow::STATUS_INVALID;
                $entry['errors'] = $errors;
                $entry['changes'] = null;
            }

            $summary['total']++;
            $summary[$entry['action'] === ImportBatchRow::ACTION_FAIL ? 'invalid' : 'valid']++;
            $summary[$this->summaryKey($entry['action'])]++;

            $planRows[] = $entry;
        }

        return ['rows' => $planRows, 'summary' => $summary];
    }

    /**
     * Execute a plan atomically: creates/updates users + profiles, persists
     * row outcomes, and finalizes the batch — all in one transaction.
     *
     * @param  array{rows: list<array<string, mixed>>, summary: array<string, int>} $plan
     * @return array<string, int> final counts (created/updated/skipped/failed)
     */
    public function commit(ImportBatch $batch, array $plan, ?int $confirmedByUserId): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
        $startedAt = now();

        DB::transaction(function () use ($batch, &$plan, &$counts, $confirmedByUserId, $startedAt) {
            foreach ($plan['rows'] as &$entry) {
                switch ($entry['action']) {
                    case ImportBatchRow::ACTION_CREATE:
                        $this->executeCreate($batch, $entry);
                        $entry['status'] = ImportBatchRow::STATUS_IMPORTED;
                        $counts['created']++;
                        break;

                    case ImportBatchRow::ACTION_UPDATE:
                        $this->executeUpdate($batch, $entry);
                        $entry['status'] = ImportBatchRow::STATUS_IMPORTED;
                        $counts['updated']++;
                        break;

                    case ImportBatchRow::ACTION_SKIP:
                        $entry['status'] = ImportBatchRow::STATUS_SKIPPED;
                        $counts['skipped']++;
                        break;

                    default:
                        $entry['status'] = ImportBatchRow::STATUS_FAILED;
                        $counts['failed']++;
                        break;
                }
            }
            unset($entry);

            $this->persistRows($batch, $plan['rows']);

            $batch->update([
                'status' => ImportBatch::STATUS_COMPLETED,
                'confirmed_by_user_id' => $confirmedByUserId,
                'total_rows' => $plan['summary']['total'],
                'valid_rows' => $plan['summary']['valid'],
                'invalid_rows' => $plan['summary']['invalid'],
                'created_count' => $counts['created'],
                'updated_count' => $counts['updated'],
                'skipped_count' => $counts['skipped'],
                'failed_count' => $counts['failed'],
                'started_at' => $startedAt,
                'completed_at' => now(),
            ]);
        });

        return $counts;
    }

    /**
     * Persist plan rows for audit/error report. PII-minimized: only
     * email/NIM/name are stored, never tanggal_lahir.
     *
     * @param list<array<string, mixed>> $planRows
     */
    public function persistRows(ImportBatch $batch, array $planRows): void
    {
        $batch->rows()->delete();

        $now = now();
        $records = array_map(function (array $entry) use ($batch, $now) {
            // Keep skip notes (e.g. ignored name/birth-date drift) in the
            // audit trail, not just in the transient dry-run response.
            $changes = $entry['changes'];
            if (!empty($entry['note'])) {
                $changes = ($changes ?? []) + ['note' => $entry['note']];
            }

            return [
                'import_batch_id' => $batch->id,
                'row_number' => $entry['row_number'],
                'email' => $entry['data']['email'] !== '' ? $entry['data']['email'] : null,
                'nim' => $entry['data']['nim'] !== '' ? $entry['data']['nim'] : null,
                'display_name' => $entry['data']['name'] !== '' ? mb_substr($entry['data']['name'], 0, 255) : null,
                'action' => $entry['action'],
                'status' => $entry['status'],
                'errors_json' => $entry['errors'] !== [] ? json_encode($entry['errors'], JSON_UNESCAPED_UNICODE) : null,
                'changes_json' => $changes !== null ? json_encode($changes, JSON_UNESCAPED_UNICODE) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $planRows);

        foreach (array_chunk($records, 500) as $chunk) {
            ImportBatchRow::insert($chunk);
        }
    }

    // ─────────────────────────── row validation ───────────────────────────

    /**
     * @param  array<string, mixed> $row
     * @param  array<string, int>   $programIdsByCode
     * @return array{0: list<string>, 1: int|null}
     */
    private function validateRow(array $row, array $programIdsByCode): array
    {
        $errors = [];

        // Template sample rows carry the reserved prodi code CONTOH so an
        // unmodified template can never create a fictional student.
        if ($row['study_program_code'] === self::SAMPLE_PROGRAM_CODE) {
            return [[
                'Baris ini adalah baris contoh dari template. Hapus baris contoh atau ganti dengan data mahasiswa yang sebenarnya.',
            ], null];
        }

        if ($row['name'] === '') {
            $errors[] = 'Nama wajib diisi.';
        } elseif (in_array($row['name'][0], self::FORMULA_PREFIXES, true)) {
            $errors[] = 'Nama tidak boleh diawali karakter formula (=, +, @).';
        }

        if ($row['email'] === '') {
            $errors[] = 'Email wajib diisi.';
        } elseif (!filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Format email tidak valid.';
        } else {
            $domain = substr(strrchr($row['email'], '@') ?: '', 1);
            if (!in_array($domain, self::ALLOWED_EMAIL_DOMAINS, true)) {
                $errors[] = 'Email harus menggunakan domain UGM (@mail.ugm.ac.id atau @ugm.ac.id).';
            }
        }

        if ($row['nim'] === null) {
            $errors[] = 'NIM wajib diisi.';
        } elseif (!NimHelper::validate($row['nim'])) {
            $errors[] = 'Format NIM tidak valid. Contoh: 24/535278/SV/12345.';
        }

        $programId = null;
        if ($row['study_program_code'] === '') {
            $errors[] = 'Kode Program Studi wajib diisi.';
        } elseif (!isset($programIdsByCode[$row['study_program_code']])) {
            $suggestion = $this->closestProgramCode($row['study_program_code'], array_keys($programIdsByCode));
            $errors[] = "Kode Program Studi '{$row['study_program_code']}' tidak ditemukan."
                . ($suggestion !== null ? " Mungkin maksud Anda: {$suggestion}?" : ' Lihat sheet "Referensi Prodi" pada template.');
        } else {
            $programId = $programIdsByCode[$row['study_program_code']];
        }

        if ($row['raw_tanggal_lahir'] !== '' && $row['tanggal_lahir'] === null) {
            $errors[] = 'Format tanggal lahir tidak dikenali. Gunakan YYYY-MM-DD atau DD/MM/YYYY.';
        }

        return [$errors, $programId];
    }

    /**
     * Nearest known prodi code (edit distance ≤ 2) for typo suggestions.
     *
     * @param list<string> $codes
     */
    private function closestProgramCode(string $input, array $codes): ?string
    {
        if ($input === '' || strlen($input) > 20) {
            return null;
        }

        $best = null;
        $bestDistance = 3;
        foreach ($codes as $code) {
            $distance = levenshtein($input, $code);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $code;
            }
        }

        return $best;
    }

    /**
     * Decide create/update/skip/fail for a structurally valid row.
     *
     * @param array<string, mixed> $entry   modified in place
     * @param array<string, mixed> $row
     * @param array<int, string>   $programCodesById
     * @param list<string>         $errors  modified in place
     */
    private function resolveConflict(
        array &$entry,
        array $row,
        ?User $existingUser,
        ?MahasiswaProfile $nimOwner,
        ?int $programId,
        array $programCodesById,
        bool $overrideExistingActive,
        array &$errors,
    ): void {
        // NIM already registered to a different account than this email's.
        if ($nimOwner !== null && ($existingUser === null || $nimOwner->user_id !== $existingUser->id)) {
            $errors[] = 'NIM sudah terdaftar pada akun lain.';

            return;
        }

        if ($existingUser === null) {
            $entry['action'] = ImportBatchRow::ACTION_CREATE;
            $entry['status'] = ImportBatchRow::STATUS_VALID;

            return;
        }

        if ($existingUser->role !== 'mahasiswa') {
            $errors[] = 'Email milik user non-mahasiswa. Tidak dapat diperbarui melalui impor.';

            return;
        }

        $entry['user_id'] = $existingUser->id;
        $profile = $existingUser->mahasiswaProfile;
        $changes = $this->diffChanges($existingUser, $profile, $row, $programId, $programCodesById);

        // Incomplete account (no NIM yet — e.g. Google-created pending
        // profile): official data may always fill the gaps.
        if (!$profile?->nim) {
            $entry['action'] = ImportBatchRow::ACTION_UPDATE;
            $entry['status'] = ImportBatchRow::STATUS_VALID;
            $entry['changes'] = $changes ?: null;

            return;
        }

        if ($changes === []) {
            $entry['action'] = ImportBatchRow::ACTION_SKIP;
            $entry['status'] = ImportBatchRow::STATUS_VALID;
            $entry['note'] = 'Data sudah sesuai. Tidak ada perubahan.';

            return;
        }

        if ($overrideExistingActive) {
            $entry['action'] = ImportBatchRow::ACTION_UPDATE;
            $entry['status'] = ImportBatchRow::STATUS_VALID;
            $entry['changes'] = $changes;

            return;
        }

        $identityChanged = isset($changes['nim']) || isset($changes['study_program']);

        if ($identityChanged) {
            $errors[] = 'Data berbeda dengan akun mahasiswa yang sudah terdaftar (NIM/Program Studi). '
                . 'Centang opsi perbarui data untuk menerapkan perubahan dari file.';

            return;
        }

        // Only name/birth-date differ: safe to skip silently-but-visibly.
        $entry['action'] = ImportBatchRow::ACTION_SKIP;
        $entry['status'] = ImportBatchRow::STATUS_VALID;
        $entry['note'] = 'Perbedaan kecil (nama/tanggal lahir) diabaikan. '
            . 'Centang opsi perbarui data untuk menerapkannya.';
    }

    /**
     * Old→new field diff for the audit trail. tanggal_lahir values are
     * redacted (flag only) because they are high-sensitivity PII.
     *
     * @param  array<string, mixed> $row
     * @param  array<int, string>   $programCodesById
     * @return array<string, mixed>
     */
    private function diffChanges(
        User $existingUser,
        ?MahasiswaProfile $profile,
        array $row,
        ?int $programId,
        array $programCodesById,
    ): array {
        $changes = [];

        if ($existingUser->name !== $row['name']) {
            $changes['name'] = ['from' => $existingUser->name, 'to' => $row['name']];
        }

        if (($profile?->nim) !== $row['nim']) {
            $changes['nim'] = ['from' => $profile?->nim, 'to' => $row['nim']];
        }

        if ($existingUser->study_program_id !== $programId) {
            $changes['study_program'] = [
                'from' => $programCodesById[$existingUser->study_program_id] ?? ($existingUser->study_program_id ? "#{$existingUser->study_program_id}" : null),
                'to' => $row['study_program_code'],
            ];
        }

        $existingTanggal = $profile?->tanggal_lahir ? substr((string) $profile->tanggal_lahir, 0, 10) : null;
        if ($row['tanggal_lahir'] !== null && $existingTanggal !== $row['tanggal_lahir']) {
            $changes['tanggal_lahir'] = 'diubah';
        }

        return $changes;
    }

    // ─────────────────────────── write helpers ───────────────────────────

    /** @param array<string, mixed> $entry */
    private function executeCreate(ImportBatch $batch, array $entry): void
    {
        $user = User::create([
            'name' => $entry['data']['name'],
            'email' => $entry['data']['email'],
            'password' => null,
            'role' => 'mahasiswa',
            'study_program_id' => $entry['study_program_id'],
            'status' => UserStatus::Active,
        ]);

        MahasiswaProfile::create([
            'user_id' => $user->id,
            'nim' => $entry['data']['nim'],
            'tanggal_lahir' => $entry['data']['tanggal_lahir'] !== '' ? $entry['data']['tanggal_lahir'] : null,
            'import_batch_id' => $batch->uuid,
            'data_source' => 'import_manual',
        ]);
    }

    /** @param array<string, mixed> $entry */
    private function executeUpdate(ImportBatch $batch, array $entry): void
    {
        $user = User::findOrFail($entry['user_id']);

        $user->update([
            'name' => $entry['data']['name'],
            'study_program_id' => $entry['study_program_id'],
            // Never lift a suspension through import.
            'status' => $user->status === UserStatus::Suspended ? UserStatus::Suspended : UserStatus::Active,
        ]);

        $profileValues = [
            'nim' => $entry['data']['nim'],
            'import_batch_id' => $batch->uuid,
            'data_source' => 'import_manual',
        ];
        if ($entry['data']['tanggal_lahir'] !== '') {
            $profileValues['tanggal_lahir'] = $entry['data']['tanggal_lahir'];
        }

        MahasiswaProfile::updateOrCreate(['user_id' => $user->id], $profileValues);
    }

    private function summaryKey(string $action): string
    {
        return match ($action) {
            ImportBatchRow::ACTION_CREATE => 'create',
            ImportBatchRow::ACTION_UPDATE => 'update',
            ImportBatchRow::ACTION_SKIP => 'skip',
            default => 'fail',
        };
    }

    // ─────────────────────────── file parsing ───────────────────────────

    /** @return list<array<string, string>> */
    private function parseCsv(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            throw new MahasiswaImportException('File tidak dapat dibaca. Silakan coba unggah ulang.');
        }

        try {
            $firstLine = fgets($handle);
            if ($firstLine === false) {
                $this->assertHeader(null);
            }

            // Strip UTF-8 BOM (our own template ships with one).
            $firstLine = preg_replace('/^\xEF\xBB\xBF/', '', (string) $firstLine);

            // Excel with Indonesian regional settings saves CSV with ';'.
            // Deterministic: only ',' and ';' are ever accepted; the strict
            // header assertion below still rejects anything malformed.
            $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

            $header = str_getcsv(rtrim($firstLine, "\r\n"), $delimiter);
            $this->assertHeader($header);

            $rows = [];
            while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
                if ($this->isEmptyRow($data)) {
                    continue;
                }
                $rows[] = $this->mapRow($data);
                $this->assertRowCap(count($rows));
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /** @return list<array<string, string>> */
    private function parseXlsx(UploadedFile $file): array
    {
        $reader = new XlsxReader();
        $reader->setReadDataOnly(true);

        try {
            $spreadsheet = $reader->load($file->getRealPath());
        } catch (\Throwable $e) {
            throw new MahasiswaImportException('File XLSX tidak dapat dibaca. Gunakan template resmi dari sistem.');
        }

        try {
            $sheet = $spreadsheet->getSheetByName(self::DATA_SHEET);
            if ($sheet === null) {
                throw new MahasiswaImportException('Sheet "Data Mahasiswa" tidak ditemukan. Gunakan template resmi dari sistem.');
            }

            // calculateFormulas=false: formula cells come back as their raw
            // "=..." text and are validated as plain data, never executed.
            $all = $sheet->toArray(null, false, false, false);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        $header = array_shift($all) ?? [];
        $this->assertHeader(array_map(fn ($cell) => $this->cellToString($cell), $header));

        $rows = [];
        foreach ($all as $data) {
            $data = array_map(fn ($cell, $index) => $this->cellToString($cell, $index === 4), $data, array_keys($data));
            if ($this->isEmptyRow($data)) {
                continue;
            }
            $rows[] = $this->mapRow($data);
            $this->assertRowCap(count($rows));
        }

        return $rows;
    }

    /**
     * Convert an XLSX cell value to a plain string. Numeric cells in the
     * tanggal_lahir column are treated as Excel date serials.
     */
    private function cellToString(mixed $value, bool $isDateColumn = false): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof RichText) {
            return trim($value->getPlainText());
        }

        if ($isDateColumn && (is_int($value) || is_float($value)) && $value > 0) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (\Throwable) {
                return trim((string) $value);
            }
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** @param array<int, mixed>|null $header */
    private function assertHeader(?array $header): void
    {
        $normalized = array_map(fn ($cell) => strtolower(trim((string) $cell)), $header ?? []);

        // Excel often appends phantom empty columns; ignore trailing blanks.
        while ($normalized !== [] && end($normalized) === '') {
            array_pop($normalized);
        }

        if ($normalized !== self::HEADERS) {
            throw new MahasiswaImportException(
                'Format kolom tidak sesuai template ' . self::TEMPLATE_VERSION
                . '. Kolom wajib: ' . implode(', ', self::HEADERS)
                . '. Unduh template terbaru dari sistem.'
            );
        }
    }

    private function assertRowCap(int $count): void
    {
        if ($count > self::MAX_ROWS) {
            throw new MahasiswaImportException(
                'File terlalu besar. Maksimal ' . number_format(self::MAX_ROWS, 0, ',', '.') . ' baris data per impor.'
            );
        }
    }

    /** @param array<int, mixed> $data */
    private function isEmptyRow(array $data): bool
    {
        foreach ($data as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, mixed> $data
     * @return array<string, string>
     */
    private function mapRow(array $data): array
    {
        return [
            'name' => (string) ($data[0] ?? ''),
            'email' => (string) ($data[1] ?? ''),
            'nim' => (string) ($data[2] ?? ''),
            'study_program_code' => (string) ($data[3] ?? ''),
            'tanggal_lahir' => (string) ($data[4] ?? ''),
        ];
    }
}
