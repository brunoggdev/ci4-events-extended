<?php

namespace Brunoggdev\EventsExtended\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class MakeEvent extends BaseCommand
{
    protected $group = 'App';
    protected $name = 'make:event';
    protected $description = 'Create a new event class in App\\Events';
    protected $usage = 'make:event [EventName] or make:event [Folder/Name]';

    protected $arguments = [
        'name' => 'The event class name. Can include subfolders (e.g. User/Registered).'
    ];

    public function run(array $params)
    {
        $input = $params[0] ?? null;

        if (!$input) {
            CLI::error('Please provide an event name.');
            return;
        }

        $parts = explode('/', $input);
        $eventName = ucfirst(array_pop($parts));

        $folderParts = array_map('ucfirst', $parts);
        $folderPath = $folderParts ? implode('/', $folderParts) . '/' : '';
        $namespaceSuffix = $folderParts ? '\\' . implode('\\', $folderParts) : '';

        $baseDir = APPPATH . "Events/{$folderPath}";
        $filePath = $baseDir . "{$eventName}.php";
        $namespace = "App\\Events{$namespaceSuffix}";

        if (file_exists($filePath)) {
            CLI::error("Event '{$eventName}' already exists at {$filePath}");
            return;
        }

        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0755, true);
            CLI::write("Created directory: {$baseDir}", 'yellow');
        }

        $template = <<<PHP
<?php

namespace {$namespace};

class {$eventName}
{
    public function __construct(
        // your event params here
    ) {}
}
PHP;

        file_put_contents($filePath, $template);

        CLI::write("Event created: {$filePath}", 'green');
    }
}
