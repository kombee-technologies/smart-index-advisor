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
    protected $description = 'Install all of the Index Advisor resources';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->comment('Publishing Index Advisor Configuration...');
        $this->callSilent('vendor:publish', ['--tag' => 'index-advisor-config']);

        $this->comment('Publishing Index Advisor Service Provider...');
        $this->callSilent('vendor:publish', ['--tag' => 'index-advisor-provider']);

        $this->comment('Publishing Index Advisor Migrations...');
        $this->callSilent('vendor:publish', ['--tag' => 'index-advisor']);

        $this->info('Index Advisor scaffolding installed successfully.');
    }
}
