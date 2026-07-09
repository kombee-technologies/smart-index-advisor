<?php

namespace Kombee\IndexAdvisor\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Kombee\IndexAdvisor\Services\IndexAdvisorTaskStore;

class RunIndexAdvisorPipelineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public string $runId,
        public array $options = [],
        public string $outputPrefix = '',
    ) {}

    public function handle(IndexAdvisorTaskStore $tasks): void
    {
        $tasks->markRunning($this->runId);

        try {
            Artisan::call('index-advisor:run', $this->options);
            $output = $this->outputPrefix.Artisan::output();

            $tasks->markCompleted($this->runId, ['output' => $output]);
        } catch (\Throwable $e) {
            $tasks->markFailed($this->runId, $e->getMessage());

            throw $e;
        }
    }
}
