<?php

namespace Tests\Feature\Peminjaman;

use Dotenv\Dotenv;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Feature\Workflow\WorkflowTestHelpers;
use Tests\TestCase;

class RoomBookingInitialSubmissionPostgreSqlConcurrencyTest extends TestCase
{
    use RoomBookingTestHelpers;
    use WorkflowTestHelpers;

    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";

    public function test_two_postgresql_processes_overlap_and_create_one_complete_aggregate(): void
    {
        if (! extension_loaded('pdo_pgsql') || ! is_file(base_path('.env'))) {
            $this->markTestSkipped('A local PostgreSQL .env connection is required.');
        }

        $environment = Dotenv::parse(File::get(base_path('.env')));
        if (($environment['DB_CONNECTION'] ?? null) !== 'pgsql') {
            $this->markTestSkipped('The local .env database is not PostgreSQL.');
        }

        $schema = 'c7b3_concurrency_'.strtolower(bin2hex(random_bytes(6)));
        $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.$schema.'-barrier';
        $storageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.$schema.'-storage';
        $originalDefault = config('database.default');
        $originalStorageRoot = config('filesystems.disks.local.root');
        $processes = [];
        $admin = $this->postgresPdo($environment);

        try {
            $admin->exec('CREATE SCHEMA "'.$schema.'"');
            File::ensureDirectoryExists($barrier);
            File::ensureDirectoryExists($storageRoot.'/private');

            config([
                'database.default' => 'c7b3_pg',
                'database.connections.c7b3_pg' => $this->postgresConfig($environment, $schema),
                'filesystems.disks.local.root' => $storageRoot.'/private',
            ]);
            DB::purge('c7b3_pg');
            $this->assertSame(0, Artisan::call('migrate', [
                '--database' => 'c7b3_pg',
                '--force' => true,
            ]));

            [$student] = $this->completeMahasiswa();
            $room = $this->classroom();
            $token = $student->createToken('c7b3-postgresql-concurrency')->plainTextToken;
            $this->installOverlapProbe();

            $start = Carbon::now(config('app.timezone'))
                ->addDays(7)
                ->setTime(10, 0)
                ->format('Y-m-d\TH:i:sP');
            $end = Carbon::parse($start)->addHours(2)->format('Y-m-d\TH:i:sP');
            $key = 'postgres-concurrent-initial-001';
            $workerEnvironment = $this->workerEnvironment(
                $environment,
                $schema,
                $barrier,
                $storageRoot,
                $token,
                (int) $room->id,
                $key,
                $start,
                $end,
            );

            foreach (['a', 'b'] as $worker) {
                $process = new Process(
                    [PHP_BINARY, base_path('tests/Support/RoomBookingConcurrentSubmissionWorker.php')],
                    base_path(),
                    array_merge($workerEnvironment, ['C7B3_WORKER_ID' => $worker]),
                );
                $process->setTimeout(30);
                $process->start();
                $processes[$worker] = $process;
            }

            $this->awaitWorkersAtBarrier($processes, $barrier);
            File::put($barrier.'/go', 'go');
            foreach ($processes as $process) {
                $process->wait();
            }

            $results = [];
            foreach (['a', 'b'] as $worker) {
                $resultPath = $barrier."/result-{$worker}.json";
                $this->assertFileExists(
                    $resultPath,
                    "Worker {$worker} failed: ".$processes[$worker]->getErrorOutput(),
                );
                $results[$worker] = json_decode(
                    File::get($resultPath),
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                );
                $this->assertArrayNotHasKey('worker_error', $results[$worker]);
                $this->assertSame(201, $results[$worker]['status']);
            }

            $this->assertSame(['false', 'true'], collect($results)
                ->pluck('replay')->sort()->values()->all());
            $this->assertSame($results['a']['body'], $results['b']['body']);
            $this->assertSame(2, DB::table('c7b3_overlap_observations')->count());
            $this->assertSame(1, (int) DB::table('c7b3_overlap_observations as left_probe')
                ->crossJoin('c7b3_overlap_observations as right_probe')
                ->whereColumn('left_probe.backend_pid', '<', 'right_probe.backend_pid')
                ->whereColumn('left_probe.entered_at', '<', 'right_probe.exited_at')
                ->whereColumn('right_probe.entered_at', '<', 'left_probe.exited_at')
                ->count());

            foreach ([
                'room_booking_requests',
                'room_booking_attachments',
                'room_booking_status_histories',
                'room_booking_submission_snapshots',
                'room_booking_audit_logs',
                'room_booking_idempotency_records',
            ] as $table) {
                $this->assertSame(1, DB::table($table)->count(), $table);
            }
            $this->assertSame(1, DB::table('room_booking_occurrences')->count());
            $this->assertSame(2, DB::table('room_booking_workflow_events')->count());

            $storedBody = DB::table('room_booking_idempotency_records')
                ->value('safe_response_body');
            $storedBody = is_string($storedBody)
                ? json_decode($storedBody, true, 512, JSON_THROW_ON_ERROR)
                : $storedBody;
            $this->assertSame($results['a']['body'], $storedBody);
            $this->assertCount(
                1,
                File::allFiles($storageRoot.'/private/room-booking-attachments'),
            );
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            DB::disconnect('c7b3_pg');
            config([
                'database.default' => $originalDefault,
                'filesystems.disks.local.root' => $originalStorageRoot,
            ]);
            DB::purge('c7b3_pg');
            $admin->exec('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
            File::deleteDirectory($barrier);
            File::deleteDirectory($storageRoot);
        }
    }

    /** @param array<string, string> $environment */
    private function postgresPdo(array $environment): \PDO
    {
        return new \PDO(
            sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                $environment['DB_HOST'] ?? '127.0.0.1',
                $environment['DB_PORT'] ?? '5432',
                $environment['DB_DATABASE'],
            ),
            $environment['DB_USERNAME'] ?? '',
            $environment['DB_PASSWORD'] ?? '',
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
    }

    /**
     * @param array<string, string> $environment
     * @return array<string, mixed>
     */
    private function postgresConfig(array $environment, string $schema): array
    {
        return [
            'driver' => 'pgsql',
            'host' => $environment['DB_HOST'] ?? '127.0.0.1',
            'port' => $environment['DB_PORT'] ?? '5432',
            'database' => $environment['DB_DATABASE'],
            'username' => $environment['DB_USERNAME'] ?? '',
            'password' => $environment['DB_PASSWORD'] ?? '',
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => $schema,
            'sslmode' => $environment['DB_SSLMODE'] ?? 'prefer',
        ];
    }

    private function installOverlapProbe(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE c7b3_overlap_observations (
                backend_pid integer PRIMARY KEY,
                entered_at timestamptz NOT NULL,
                exited_at timestamptz NULL
            );
            CREATE FUNCTION c7b3_initial_submission_overlap_probe()
            RETURNS trigger AS $$
            BEGIN
                INSERT INTO c7b3_overlap_observations (backend_pid, entered_at)
                VALUES (pg_backend_pid(), clock_timestamp());
                PERFORM pg_sleep(1);
                UPDATE c7b3_overlap_observations
                SET exited_at = clock_timestamp()
                WHERE backend_pid = pg_backend_pid();
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER c7b3_initial_submission_overlap
            BEFORE INSERT ON room_booking_idempotency_records
            FOR EACH ROW
            WHEN (NEW.subject_key = 'initial-submission')
            EXECUTE FUNCTION c7b3_initial_submission_overlap_probe();
            SQL);
    }

    /**
     * @param array<string, string> $environment
     * @return array<string, string>
     */
    private function workerEnvironment(
        array $environment,
        string $schema,
        string $barrier,
        string $storageRoot,
        string $token,
        int $roomId,
        string $key,
        string $start,
        string $end,
    ): array {
        return [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => $environment['DB_HOST'] ?? '127.0.0.1',
            'DB_PORT' => $environment['DB_PORT'] ?? '5432',
            'DB_DATABASE' => $environment['DB_DATABASE'],
            'DB_USERNAME' => $environment['DB_USERNAME'] ?? '',
            'DB_PASSWORD' => $environment['DB_PASSWORD'] ?? '',
            'DB_SSLMODE' => $environment['DB_SSLMODE'] ?? 'prefer',
            'C7B3_PG_SCHEMA' => $schema,
            'C7B3_BARRIER_DIR' => $barrier,
            'C7B3_STORAGE_ROOT' => $storageRoot,
            'C7B3_AUTH_TOKEN' => $token,
            'C7B3_ROOM_ID' => (string) $roomId,
            'C7B3_IDEMPOTENCY_KEY' => $key,
            'C7B3_START_AT' => $start,
            'C7B3_END_AT' => $end,
            'C7B3_PDF_BYTES' => self::PDF,
        ];
    }

    /** @param array<string, Process> $processes */
    private function awaitWorkersAtBarrier(array $processes, string $barrier): void
    {
        $deadline = microtime(true) + 15;
        while (! (is_file($barrier.'/ready-a') && is_file($barrier.'/ready-b'))) {
            foreach ($processes as $worker => $process) {
                if (! $process->isRunning()) {
                    $this->fail("Worker {$worker} exited before the barrier: ".$process->getErrorOutput());
                }
            }
            if (microtime(true) >= $deadline) {
                $this->fail('PostgreSQL concurrency workers did not reach the barrier.');
            }
            usleep(10_000);
        }
    }
}
