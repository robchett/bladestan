<?php

namespace TomasVotruba\Bladestan;

use Illuminate\Support\ServiceProvider;
use TomasVotruba\Bladestan\Commands\DumpViewAliases;

class BladestanServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->commands([DumpViewAliases::class]);
    }
}
