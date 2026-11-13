<?php

declare(strict_types=1);

namespace Brunoggdev\EventsExtended\Commands;

use Brunoggdev\EventsExtended\Events;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class EventsList extends BaseCommand
{
    protected $group       = 'EventsExtended';
    protected $name        = 'events:list';
    protected $description = 'Lists registered events and their listeners.';
    protected $usage       = 'events:list [--event Name] [--listener Name]';
    protected $options     = [
        '--event'    => 'Show the listeners an event actually reaches, in order (class, parents, interfaces). FQCN or a registered short name.',
        '--listener' => 'Only show where this listener is registered. FQCN or short name.',
    ];

    public function run(array $params): void
    {
        $registry = Events::describe();
        $event    = $params['event'] ?? CLI::getOption('event');
        $listener = $params['listener'] ?? CLI::getOption('listener');

        if (is_string($event) && $event !== '') {
            $this->showEvent($event, $registry);

            return;
        }

        ksort($registry, SORT_NATURAL | SORT_FLAG_CASE);

        $rows = [];

        foreach ($registry as $name => $listeners) {
            foreach ($listeners as $entry) {
                if (is_string($listener) && $listener !== '' && ! $this->matches($entry['listener'], $listener)) {
                    continue;
                }

                $rows[] = [$name, $entry['listener'], $entry['priority'], $this->flags($entry)];
            }
        }

        if ($rows === []) {
            CLI::write('No listeners found.', 'yellow');

            return;
        }

        CLI::table($rows, ['Event', 'Listener', 'Priority', 'Flags']);

        foreach (array_keys($registry) as $name) {
            if (class_exists($name)) {
                $this->warnDuplicates($name, $registry);
            }
        }
    }

    /**
     * @param array<string, list<array{listener: string, priority: int, defer: bool, silent: bool}>> $registry
     */
    private function showEvent(string $input, array $registry): void
    {
        $name = $this->resolveEvent($input, $registry);

        if ($name === null) {
            CLI::error("Event '{$input}' not found. Use its fully qualified class name if it is only reached through a parent or interface.");

            return;
        }

        $types = class_exists($name) ? Events::typesFor($name) : [$name];
        $rows  = [];

        foreach ($types as $type) {
            foreach ($registry[$type] ?? [] as $entry) {
                $rows[] = [$type === $name ? '(self)' : $type, $entry['listener'], $entry['priority'], $this->flags($entry)];
            }
        }

        CLI::write($name, 'green');

        if ($rows === []) {
            CLI::write('No listeners.', 'yellow');

            return;
        }

        CLI::table($rows, ['Via', 'Listener', 'Priority', 'Flags']);

        if (class_exists($name)) {
            $this->warnDuplicates($name, $registry);
        }
    }

    /**
     * Accepts an existing class or event name, or the short name of a registered one.
     *
     * @param array<string, list<array<string, mixed>>> $registry
     */
    private function resolveEvent(string $input, array $registry): ?string
    {
        $input = ltrim($input, '\\');

        if (class_exists($input) || interface_exists($input) || isset($registry[$input])) {
            return $input;
        }

        $found = array_values(array_filter(
            array_keys($registry),
            fn (string $name): bool => $this->matches($name, $input),
        ));

        return count($found) === 1 ? $found[0] : null;
    }

    /**
     * Warns about listeners reached more than once by the same event class.
     *
     * @param class-string                                                                           $class
     * @param array<string, list<array{listener: string, priority: int, defer: bool, silent: bool}>> $registry
     */
    private function warnDuplicates(string $class, array $registry): void
    {
        $seen = [];

        foreach (Events::typesFor($class) as $type) {
            foreach ($registry[$type] ?? [] as $entry) {
                if ($entry['listener'] !== 'Closure') {
                    $seen[$entry['listener']][] = $type;
                }
            }
        }

        foreach ($seen as $listener => $types) {
            if (count($types) > 1) {
                CLI::write("Warning: {$listener} runs " . count($types) . " times for {$class} (via " . implode(', ', $types) . ').', 'yellow');
            }
        }
    }

    private function matches(string $name, string $input): bool
    {
        $input = ltrim($input, '\\');

        return strcasecmp($name, $input) === 0
            || strcasecmp(substr(strrchr('\\' . $name, '\\'), 1), $input) === 0;
    }

    /**
     * @param array{defer: bool, silent: bool} $entry
     */
    private function flags(array $entry): string
    {
        return implode(', ', array_keys(array_filter([
            'defer'  => $entry['defer'],
            'silent' => $entry['silent'],
        ])));
    }
}
