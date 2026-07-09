<?php

namespace Kombee\IndexAdvisor\Console\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'index-advisor:install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install all of the Smart Index Advisor resources';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->comment('Publishing Smart Index Advisor Configuration...');
        $this->callSilent('vendor:publish', ['--tag' => 'smart-index-advisor-config']);

        $this->comment('Publishing Smart Index Advisor Service Provider...');
        $this->callSilent('vendor:publish', ['--tag' => 'smart-index-advisor-provider']);

        $this->comment('Publishing Smart Index Advisor Migrations...');
        $this->callSilent('vendor:publish', ['--tag' => 'smart-index-advisor']);

        $this->info('Smart Index Advisor scaffolding installed successfully.');
    }
}
