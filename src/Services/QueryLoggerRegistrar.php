<?php

namespace Kombee\IndexAdvisor\Services;

use Illuminate\Support\Facades\DB;

class QueryLoggerRegistrar
{
    public function __construct(private RuntimeQueryListener $listener) {}

    public function register(): void
    {
        if (! config('index_advisor.enabled')) {
            return;
        }

        DB::listen(function ($query) {
            $this->listener->handle($query);
        });
    }
}
