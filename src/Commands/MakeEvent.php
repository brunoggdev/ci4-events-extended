<?php

declare(strict_types=1);

namespace Brunoggdev\EventsExtended\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\GeneratorTrait;

class MakeEvent extends BaseCommand
{
    use GeneratorTrait;

    protected $group       = 'EventsExtended';
    protected $name        = 'make:event';
    protected $description = 'Generates a new event class.';
    protected $usage       = 'make:event <name> [options]';
    protected $arguments   = [
        'name' => 'The event class name. Can include subfolders (e.g. User/Registered).',
    ];
    protected $options = [
        '--namespace' => 'Set root namespace. Default: "APP_NAMESPACE".',
        '--force'     => 'Force overwrite existing file.',
    ];

    public function run(array $params): void
    {
        $this->component = 'Event';
        $this->directory = 'Events';
        $this->template  = 'event.tpl.php';

        $this->classNameLang = 'CLI.generator.className.default';
        $this->generateClass($params);
    }

    protected function renderTemplate(array $data = []): string
    {
        return view('Brunoggdev\EventsExtended\Commands\Views\\' . $this->template, $data, ['debug' => false]);
    }
}
