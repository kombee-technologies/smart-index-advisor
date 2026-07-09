<?php

namespace Kombee\IndexAdvisor\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Telescope\Telescope;

class RuntimeQueryListener
{
    /** @var array<string, array{fingerprint: string, sql_sample: string, execution_count: int, total_duration_ms: float, max_duration_ms: float, last_seen_at: mixed}> */
    private array $queryBuffer = [];

    /** @var array<string, array{driver: string, explain: mixed}> */
    private array $explainBuffer = [];

    public function __construct(
        private QueryFingerprinter $fingerprinter,
        private ExplainPlanRunner $explainRunner,
        private QueryLogUpserter $queryLogUpserter,
    ) {}

    public function handle(object $query): void
    {
        $sql = $query->sql;
        $duration = $query->time;

        if ($this->shouldSkipQueryLogging($sql)) {
            return;
        }

        $fingerprint = $this->fingerprinter->fingerprint($sql);

        try {
            $driver = DB::getDriverName();
            $hasFullScan = false;
            $explain = null;

            if ($duration > config('index_advisor.slow_query_ms', 200)) {
                $explainResult = $this->explainRunner->run($driver, $sql, $query->bindings);
                if ($explainResult !== null) {
                    [$hasFullScan, $explain] = $explainResult;
                }
            }

            // Buffer instead of writing immediately — reduces per-request DB round-trips
            // and allows aggregation of identical fingerprints within the request.
            $sqlSample = Str::limit($sql, 2000);
            $lastSeenAt = now();

            if (isset($this->queryBuffer[$fingerprint])) {
                $this->queryBuffer[$fingerprint]['execution_count']++;
                $this->queryBuffer[$fingerprint]['total_duration_ms'] += (float) $duration;
                $this->queryBuffer[$fingerprint]['max_duration_ms'] = max(
                    $this->queryBuffer[$fingerprint]['max_duration_ms'],
                    (float) $duration
                );
                $this->queryBuffer[$fingerprint]['last_seen_at'] = $lastSeenAt;
            } else {
                $this->queryBuffer[$fingerprint] = [
                    'fingerprint' => $fingerprint,
                    'sql_sample' => $sqlSample,
                    'execution_count' => 1,
                    'total_duration_ms' => (float) $duration,
                    'max_duration_ms' => (float) $duration,
                    'last_seen_at' => $lastSeenAt,
                ];
            }

            if ($hasFullScan && $explain !== null) {
                $this->explainBuffer[$fingerprint] = [
                    'driver' => $driver,
                    'explain' => $explain,
                ];
            }
        } catch (\Throwable $e) {
            $this->logError('IndexAdvisor listener error', $e, ['sql' => Str::limit($sql, 200)]);
        }
    }

    /**
     * Flush buffered queries and explains to the database.
     * Called by FlushQueryBufferMiddleware::terminate() after the response is sent.
     */
    public function flush(): void
    {
        if ($this->queryBuffer === [] && $this->explainBuffer === []) {
            return;
        }

        foreach ($this->queryBuffer as $data) {
            $this->queryLogUpserter->recordBuffered(
                $data['fingerprint'],
                $data['sql_sample'],
                $data['execution_count'],
                $data['total_duration_ms'],
                $data['max_duration_ms'],
                $data['last_seen_at'],
            );
        }

        foreach ($this->explainBuffer as $fingerprint => $data) {
            $this->storeExplain($data['driver'], $fingerprint, $data['explain']);
        }

        $this->queryBuffer = [];
        $this->explainBuffer = [];
    }

    private function storeExplain(string $driver, string $fingerprint, mixed $explain): void
    {
        if ($driver === 'pgsql') {
            DB::statement(
                'INSERT INTO index_advisor_explains
                    (fingerprint, driver, has_full_scan, raw_plan, analyzed_at)
                 VALUES (?, ?, true, ?, ?)
                 ON CONFLICT (fingerprint) DO UPDATE SET
                    driver        = EXCLUDED.driver,
                    has_full_scan = true,
                    raw_plan      = EXCLUDED.raw_plan,
                    analyzed_at   = EXCLUDED.analyzed_at',
                [$fingerprint, $driver, json_encode($explain), now()]
            );

            return;
        }

        DB::table('index_advisor_explains')->upsert(
            [
                'fingerprint' => $fingerprint,
                'driver' => $driver,
                'has_full_scan' => true,
                'raw_plan' => json_encode($explain),
                'analyzed_at' => now(),
            ],
            ['fingerprint'],
            ['driver', 'has_full_scan', 'raw_plan', 'analyzed_at']
        );
    }

    private function shouldSkipQueryLogging(string $sql): bool
    {
        if (app()->bound('request')) {
            $request = app('request');

            if ($request->attributes->get('index_advisor_skip_logging')) {
                return true;
            }

            $path = trim((string) config('index_advisor.dashboard.path', 'index-advisor'), '/');
            if ($path !== '' && $request->is($path, $path.'/*')) {
                return true;
            }
        }

        if (Str::contains($sql, 'index_advisor_')) {
            return true;
        }

        if (Str::contains(strtolower($sql), [
            'telescope_entries',
            'telescope_monitoring',
            'telescope_entries_tags',
        ])) {
            return true;
        }

        if (config('index_advisor.query_logging.skip_when_telescope_recording', false)
            && class_exists(Telescope::class)
            && Telescope::isRecording()) {
            return true;
        }

        return false;
    }

    /**
     * Log an error using the configured Smart Index Advisor log channel and level.
     * Always logs regardless of app.debug — production errors must be visible.
     */
    private function logError(string $message, \Throwable $e, array $context = []): void
    {
        $channel = config('index_advisor.log_channel');

        /** @var \Illuminate\Log\LogManager $logManager */
        $logManager = app('log');
        $logger = $channel ? $logManager->channel($channel) : $logManager->driver();

        $logger->error($message, array_merge($context, [
            'exception' => $e->getMessage(),
            'class' => get_class($e),
        ]));
    }
}
