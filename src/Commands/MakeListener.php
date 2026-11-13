<?php

declare(strict_types=1);

namespace Brunoggdev\EventsExtended\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\CLI\GeneratorTrait;

class MakeListener extends BaseCommand
{
    use GeneratorTrait;

    protected $group       = 'EventsExtended';
    protected $name        = 'make:listener';
    protected $description = 'Generates a new listener class, its event if missing, and registers both in Config/Events.php.';
    protected $usage       = 'make:listener <name> <event> [options]';
    protected $arguments   = [
        'name'  => 'The listener class name.',
        'event' => 'The event it listens to: a name inside the Events folder (e.g. UserUpdated) or a FQCN.',
    ];
    protected $options = [
        '--namespace' => 'Set root namespace. Default: "APP_NAMESPACE".',
        '--force'     => 'Force overwrite existing file.',
    ];

    /**
     * Fully qualified class name of the event being listened to.
     */
    private string $eventClass = '';

    public function run(array $params): void
    {
        $this->component = 'Listener';
        $this->directory = 'Events\\Listeners';
        $this->template  = 'listener.tpl.php';
        $this->params    = $params;

        $event = $params[1] ?? CLI::getSegment(3);

        if (! is_string($event) || $event === '') {
            CLI::error('You must provide an event in the second argument.');

            return;
        }

        $listenerClass = $this->qualifyClassName();

        // Events live next to the Listeners folder: App\Events\Listeners\X => App\Events\{event}
        $this->eventClass = str_contains($event, '\\')
            ? ltrim($event, '\\')
            : preg_replace('/\\\\Listeners$/', '', $this->namespaceOf($listenerClass)) . '\\' . str_replace('/', '\\', $event);

        $this->generateClass($this->params);

        $target = $this->buildPath($listenerClass);

        if ($target === '' || ! is_file($target)) {
            return;
        }

        if (! str_contains($event, '\\') && ! class_exists($this->eventClass)) {
            $this->call('make:event', [$this->eventClass, 'namespace' => $this->getOption('namespace') ?? APP_NAMESPACE]);
        }

        $this->updateEventsFile($listenerClass, $this->eventClass);
    }

    protected function prepare(string $class): string
    {
        return $this->parseTemplate(
            $class,
            ['{eventClass}', '{event}'],
            [$this->eventClass, $this->shortName($this->eventClass)],
        );
    }

    protected function renderTemplate(array $data = []): string
    {
        return view('Brunoggdev\EventsExtended\Commands\Views\\' . $this->template, $data, ['debug' => false]);
    }

    /**
     * Update Config/Events.php:
     *  - ensure grouped imports for the listener and event namespaces are present and alphabetical
     *  - ensure the Events::listen([...]) array contains the event => [listeners...] mapping, merged with existing entries
     */
    protected function updateEventsFile(string $listenerFQCN, string $eventFQCN): void
    {
        $path = APPPATH . 'Config/Events.php';

        if (! file_exists($path)) {
            CLI::error("Config/Events.php not found at {$path}");

            return;
        }

        $content = file_get_contents($path);

        $eventShort    = $this->shortName($eventFQCN);
        $listenerShort = $this->shortName($listenerFQCN);

        // 1) Update grouped imports for Listeners
        $content = $this->ensureGroupedImport($content, $this->namespaceOf($listenerFQCN), $listenerShort, $listenerFQCN);

        // 2) Update grouped imports for Events
        $content = $this->ensureGroupedImport($content, $this->namespaceOf($eventFQCN), $eventShort, $eventFQCN);

        // 3) Update Events::listen([...]) block (merge and sort)
        $content = $this->mergeListenBlock($content, $eventShort, $listenerShort);

        // 4) Make sure Events resolves to the package class
        $content = $this->ensureEventsImport($content);

        // Save back
        file_put_contents($path, $content);

        CLI::write('Updated Config/Events.php (imports + listen block)', 'green');
    }

    /**
     * Ensure a grouped import block exists and contains the short name.
     * The function will:
     *  - find a block like: use App\Events\Listeners\{ ... };
     *  - if not present, it will insert one near other use statements (after opening php and namespace)
     *  - merge the new short name, dedupe, sort, and write back
     */
    protected function ensureGroupedImport(string $content, string $baseNamespace, string $shortName, string $fqcn): string
    {
        // Pattern for grouped block
        $escapedBase = preg_quote($baseNamespace, '/');
        $pattern     = "/use\\s+{$escapedBase}\\\\\\{([\\s\\S]*?)\\};/m";

        if (preg_match($pattern, $content, $m)) {
            // existing grouped block: parse items
            $inner = trim($m[1]);
            $items = $this->splitImportItemsBlock($inner);

            if (! in_array($shortName, $items, true)) {
                $items[] = $shortName;
            }

            $items = $this->uniqueSorted($items);

            $newInner    = '    ' . implode(",\n    ", $items) . "\n";
            $replacement = "use {$baseNamespace}\\{\n{$newInner}};";

            return preg_replace($pattern, $replacement, $content, 1);
        }

        // grouped block not present: check if there are any single use statements under the same baseNamespace
        // collect existing single 'use App\Events\X;' lines and convert to grouped plus the new one
        $singlePattern = '/use\\s+' . preg_quote($baseNamespace, '/') . '\\\\([A-Za-z0-9_\\\\]+)\\s*;/m';
        preg_match_all($singlePattern, $content, $singles);

        $items = [];

        if (! empty($singles[1])) {
            foreach ($singles[1] as $s) {
                // Keep only the final short name (strip sub-namespaces)
                $parts   = explode('\\', $s);
                $items[] = end($parts);
            }

            // Remove those single use lines (we will replace by grouped import)
            $content = preg_replace($singlePattern, '', $content);
        }

        if (! in_array($shortName, $items, true)) {
            $items[] = $shortName;
        }

        $items = $this->uniqueSorted($items);

        // Build grouped import block
        $inner = '    ' . implode(",\n    ", $items) . "\n";
        $group = "use {$baseNamespace}\\{\n{$inner}};";

        // Insert grouped block after the opening <?php and namespace (if present), otherwise at top
        if (preg_match('/namespace\s+[A-Za-z0-9_\\\\]+;\s*/', $content, $nm, PREG_OFFSET_CAPTURE)) {
            $insertPos = $nm[0][1] + strlen($nm[0][0]);
            $content   = substr_replace($content, "\n{$group}\n", $insertPos, 0);
        } else {
            // just place after <?php
            $content = preg_replace('/<\?php\s*/', "<?php\n\n{$group}\n", $content, 1);
        }

        return $content;
    }

    /**
     * Split inner block items like:
     * "Foo,\n    Bar,\n" --> ['Foo','Bar']
     * Handles trailing commas and empty lines.
     */
    protected function splitImportItemsBlock(string $inner): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $inner);
        $items = [];

        foreach ($lines as $line) {
            $line = trim($line);
            $line = rtrim($line, ',');
            if ($line === '') {
                continue;
            }
            $items[] = $line;
        }

        return $items;
    }

    protected function uniqueSorted(array $items): array
    {
        $items = array_values(array_unique($items));
        usort($items, static fn ($a, $b) => strcasecmp($a, $b));

        return $items;
    }

    /**
     * Merge/insert into listen([...]) block.
     * The function parses the existing block extracting entries of the form:
     *   SomeEvent::class => [ HandlerOne::class, HandlerTwo::class, ... ],
     * and merges the new event/listener, preserving other entries and sorting everything.
     */
    protected function mergeListenBlock(string $content, string $eventShort, string $listenerShort): string
    {
        $pattern = '/(?:Events::)?listen\s*\(\s*\[(.*?)\]\s*\);/s';

        if (! preg_match($pattern, $content, $m)) {
            // no listen block found - append a new one at the end of imports area
            $newMap = [
                $eventShort => [$listenerShort],
            ];
            $newListen = $this->buildListenBlockFromMap($newMap);
            // place after the last import if present, otherwise append at file end
            if (preg_match_all('/^use\s+[^;]+;/m', $content, $mm, PREG_OFFSET_CAPTURE) > 0) {
                $last    = end($mm[0]);
                $pos     = $last[1] + strlen($last[0]);
                $content = substr_replace($content, "\n\n{$newListen}\n", $pos, 0);
            } else {
                $content .= "\n\n{$newListen}\n";
            }

            return $content;
        }

        $block = $m[1];

        // Find all event entries (capture event short name and inner handlers)
        // This regex captures "Something::class => [ ... ]," across multiple lines.
        preg_match_all('/([A-Za-z0-9_\\\\]+)::class\s*=>\s*\[\s*([\s\S]*?)\s*\]\s*,?/m', $block, $pairs, PREG_SET_ORDER);

        $map = [];

        foreach ($pairs as $p) {
            $evt           = trim($p[1]); // short or namespaced, but in practice short
            $handlersBlock = trim($p[2]);

            // Extract handler short names
            preg_match_all('/([A-Za-z0-9_\\\\]+)::class/', $handlersBlock, $hs);
            $handlers = array_map(static function ($h) {
                $parts = explode('\\', $h);

                return end($parts);
            }, $hs[1]);

            $map[$evt] = $handlers;
        }

        // Merge in new event/listener
        if (! isset($map[$eventShort])) {
            $map[$eventShort] = [];
        }
        if (! in_array($listenerShort, $map[$eventShort], true)) {
            $map[$eventShort][] = $listenerShort;
        }

        // Keep listener order (it is the execution order), sort events only
        foreach ($map as $k => $list) {
            $map[$k] = array_values(array_unique($list));
        }
        ksort($map, SORT_NATURAL | SORT_FLAG_CASE);

        // Rebuild listen block
        $newListen = $this->buildListenBlockFromMap($map);

        // Replace the old listen block in the content
        return preg_replace($pattern, $newListen, $content, 1);
    }

    /**
     * Swap CodeIgniter's Events import for the package one, or add it when missing.
     */
    protected function ensureEventsImport(string $content): string
    {
        $import = 'use Brunoggdev\\EventsExtended\\Events;';

        if (str_contains($content, $import)) {
            return $content;
        }

        if (preg_match('/^use\s+CodeIgniter\\\\Events\\\\Events\s*;/m', $content)) {
            return preg_replace('/^use\s+CodeIgniter\\\\Events\\\\Events\s*;/m', $import, $content, 1);
        }

        if (preg_match('/namespace\s+[A-Za-z0-9_\\\\]+;\s*/', $content, $nm, PREG_OFFSET_CAPTURE)) {
            return substr_replace($content, "\n{$import}\n", $nm[0][1] + strlen($nm[0][0]), 0);
        }

        return preg_replace('/<\?php\s*/', "<?php\n\n{$import}\n", $content, 1);
    }

    protected function buildListenBlockFromMap(array $map): string
    {
        $lines = [];

        foreach ($map as $evt => $handlers) {
            $lines[] = "    {$evt}::class => [";

            foreach ($handlers as $h) {
                $lines[] = "        {$h}::class,";
            }
            $lines[] = '    ],';
        }

        $inner = implode("\n", $lines);

        return "Events::listen([\n{$inner}\n]);";
    }

    private function shortName(string $class): string
    {
        return substr(strrchr('\\' . $class, '\\'), 1);
    }

    private function namespaceOf(string $class): string
    {
        return substr($class, 0, (int) strrpos($class, '\\'));
    }
}
