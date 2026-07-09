<?php

namespace Kombee\IndexAdvisor\Http\Controllers\Concerns;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Kombee\IndexAdvisor\Services\IndexAdvisorTaskStore;

trait DispatchesIndexAdvisorTasks
{
    protected function dispatchIndexAdvisorTask(object $job): JsonResponse
    {
        $tasks = app(IndexAdvisorTaskStore::class);
        $tasks->createPending($job->runId);

        app(Dispatcher::class)->dispatch($job);

        $result = $tasks->get($job->runId);

        if ($result !== null && in_array($result['status'], ['completed', 'failed'], true)) {
            return $this->taskResultResponse($job->runId, $result);
        }

        return response()->json([
            'success' => true,
            'queued' => true,
            'run_id' => $job->runId,
            'message' => 'Task queued. Poll /api/tasks/{run_id} for status.',
        ], 202);
    }

    protected function taskResultResponse(string $runId, array $result): JsonResponse
    {
        if (($result['status'] ?? null) === 'failed' || ! ($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'run_id' => $runId,
                'error' => $result['error'] ?? 'Task failed.',
            ], 500);
        }

        return response()->json(array_merge([
            'success' => true,
            'run_id' => $runId,
            'queued' => false,
        ], $result));
    }

    public function getTaskStatus(string $runId): JsonResponse
    {
        $result = app(IndexAdvisorTaskStore::class)->get($runId);

        if ($result === null) {
            return response()->json(['error' => 'Task not found.'], 404);
        }

        return response()->json(array_merge(['run_id' => $runId], $result));
    }

    protected function newTaskId(): string
    {
        return (string) Str::uuid();
    }
}
