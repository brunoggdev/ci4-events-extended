<?php

declare(strict_types=1);

namespace Brunoggdev\EventsExtended\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class EventsPublish extends BaseCommand
{
    protected $group       = 'EventsExtended';
    protected $name        = 'events:publish';
    protected $description = 'Publishes the EventsExtended config file into the application config folder.';

    public function run(array $params): void
    {
        $source = __DIR__ . '/../Config/EventsExtended.php';
        $dest   = APPPATH . 'Config/EventsExtended.php';

        if (file_exists($dest)) {
            $overwrite = CLI::prompt('EventsExtended.php already exists. Overwrite?', ['y', 'n']) === 'y';

            if (! $overwrite) {
                CLI::write('Publish cancelled.', 'yellow');

                return;
            }
        }

        $contents = file_get_contents($source);

        // Swap package namespace for app namespace
        $contents = str_replace(
            'namespace Brunoggdev\EventsExtended\Config;',
            'namespace Config;',
            $contents,
        );

        // Swap parent import
        $contents = str_replace(
            'use CodeIgniter\Config\BaseConfig;',
            'use Brunoggdev\EventsExtended\Config\EventsExtended as BaseEventsExtended;',
            $contents,
        );

        // Extend package config instead of BaseConfig
        $contents = str_replace(
            'class EventsExtended extends BaseConfig',
            'class EventsExtended extends BaseEventsExtended',
            $contents,
        );

        if (! file_put_contents($dest, $contents)) {
            CLI::error('Error publishing EventsExtended.php.');

            return;
        }

        CLI::write('EventsExtended.php published successfully.', 'green');
        CLI::write('You can now customize it at: ' . $dest, 'white');
    }
}
