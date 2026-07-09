<?php

namespace Kombee\IndexAdvisor\Services;

use Illuminate\Support\Facades\Cache;

class IndexAdvisorTaskStore
{
    public function key(string $runId): string
    {
        return 'index_advisor:task:'.$runId;
    }

    public function createPending(string $runId): void
    {
        Cache::put($this->key($runId), ['status' => 'pending'], now()->addHour());
    }

    public function markRunning(string $runId): void
    {
        Cache::put($this->key($runId), ['status' => 'running'], now()->addHour());
    }

    public function markCompleted(string $runId, array $payload): void
    {
        Cache::put(
            $this->key($runId),
            array_merge(['status' => 'completed', 'success' => true], $payload),
            now()->addHour()
        );
    }

    public function markFailed(string $runId, string $error): void
    {
        Cache::put(
            $this->key($runId),
            ['status' => 'failed', 'success' => false, 'error' => $error],
            now()->addHour()
        );
    }

    public function get(string $runId): ?array
    {
        $result = Cache::get($this->key($runId));

        return is_array($result) ? $result : null;
    }
}
