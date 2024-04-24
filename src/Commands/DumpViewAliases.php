<?php

namespace TomasVotruba\Bladestan\Commands;

use Evosite\OxygenCms\Database\Factories\OxygenModelFactory;
use Evosite\OxygenCms\Facades\SchemaRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\FileViewFinder;
use SebastianBergmann\CodeCoverage\Report\PHP;

class DumpViewAliases extends Command
{
    public $signature = 'bladestan:dump-aliases';

    public $description = 'Dumps view aliases so that bladstan can configure itself';

    public function handle(): int
    {
        file_put_contents(app()->storagePath('framework/testing/bladestan-aliases.json'), json_encode([
            'aliases' => Blade::getClassComponentAliases(),
            'namespaces' => app()->make('view')->getFinder()->getHints(),
            'paths' => app()->make('view')->getFinder()->getPaths(),
        ]));

        return self::SUCCESS;
    }
}
