<?php

namespace Kombee\IndexAdvisor\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Kombee\IndexAdvisor\Services\IndexAdvisorTaskStore;

class GenerateIndexAdvisorMigrationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $options
     * @param  list<string>  $skipped
     */
    public function __construct(
        public string $runId,
        public array $options = [],
        public ?int $generatedCount = null,
        public array $skipped = [],
    ) {}

    public function handle(IndexAdvisorTaskStore $tasks): void
    {
        $tasks->markRunning($this->runId);

        try {
            Artisan::call('index-advisor:generate-migrations', $this->options);
            $output = Artisan::output();

            $tasks->markCompleted($this->runId, [
                'output' => $output,
                'generated_count' => $this->generatedCount,
                'skipped' => $this->skipped,
            ]);
        } catch (\Throwable $e) {
            $tasks->markFailed($this->runId, $e->getMessage());

            throw $e;
        }
    }
}
