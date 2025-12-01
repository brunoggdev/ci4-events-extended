<?php

namespace Brunoggdev\EventsExtended\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class MakeListener extends BaseCommand
{
    protected $group       = 'App';
    protected $name        = 'make:listener';
    protected $description = 'Creates a new event listener class and registers it into Config/Events.php';

    protected $usage     = 'make:listener ListenerName EventName';
    protected $arguments = [];
    protected $options   = [];

    public function run(array $params)
    {
        $listener = $params[0] ?? null;
        $event    = $params[1] ?? null;

        if (! $listener) {
            CLI::error('You must provide a listener name.');
            return;
        }

        if (! $event) {
            CLI::error('You must provide an event in the second argument.');
            return;
        }

        $listener = ucfirst($listener);
        $event    = ucfirst($event);

        $listenerPath = APPPATH . "Events/Listeners/{$listener}.php";
        $eventPath    = APPPATH . "Events/{$event}.php";

        // Ensure directories
        if (! is_dir(APPPATH . 'Events/Listeners')) {
            mkdir(APPPATH . 'Events/Listeners', 0777, true);
        }
        if (! is_dir(APPPATH . 'Events')) {
            mkdir(APPPATH . 'Events', 0777, true);
        }

        // Create listener if needed
        if (! file_exists($listenerPath)) {
            file_put_contents($listenerPath, $this->listenerTemplate($listener, $event));
            CLI::write("Created Listener: {$listenerPath}", 'green');
        } else {
            CLI::write("Listener already exists: {$listenerPath}", 'yellow');
        }

        // Create event if needed
        if (! file_exists($eventPath)) {
            // Prefer calling make:event if it exists (DRY)
            if (function_exists('command')) {
                // attempt to call existing make:event command
                @command('make:event ' . $event);
            }
            if (! file_exists($eventPath)) {
                // fallback: create minimal file
                file_put_contents($eventPath, $this->eventTemplate($event));
            }
            CLI::write("Created Event: {$eventPath}", 'green');
        } else {
            CLI::write("Event already exists: {$eventPath}", 'yellow');
        }

        // Update Events.php (imports + listen block)
        $this->updateEventsFile($listener, $event);
    }

    protected function listenerTemplate(string $listener, string $event): string
    {
        return <<<PHP
<?php

namespace App\Events\Listeners;

use App\Events\\{$event};

class {$listener}
{
    /**
     * Handle the event.
     * 
     * You can return `false` to stop subsequent listeners from being called
     */
    public function __invoke({$event} \$event)
    {
        // Handle the event
    }
}

PHP;
    }

    protected function eventTemplate(string $event): string
    {
        return <<<PHP
<?php

namespace App\Events;

class {$event}
{
    // Add event data here
}

PHP;
    }

    /**
     * Update Config/Events.php:
     *  - ensure imports inside the two grouped blocks (Listeners and Events) are present and alphabetical
     *  - ensure the listen([...]) array contains the event => [listeners...] mapping, merged with existing entries
     */
    protected function updateEventsFile(string $listener, string $event): void
    {
        $path = APPPATH . 'Config/Events.php';

        if (! file_exists($path)) {
            CLI::error("Config/Events.php not found at {$path}");
            return;
        }

        $content = file_get_contents($path);

        // Compute FQCNs and short names
        $eventFQCN = "App\\Events\\{$event}";
        $listenerFQCN = "App\\Events\\Listeners\\{$listener}";
        $eventShort = $event;
        $listenerShort = $listener;

        // 1) Update grouped imports for Listeners
        $content = $this->ensureGroupedImport($content, 'App\\Events\\Listeners', $listenerShort, $listenerFQCN);

        // 2) Update grouped imports for Events
        $content = $this->ensureGroupedImport($content, 'App\\Events', $eventShort, $eventFQCN);

        // 3) Update listen([...]) block (merge and sort)
        $content = $this->mergeListenBlock($content, $eventShort, $listenerShort);

        // Save back
        file_put_contents($path, $content);

        CLI::write("Updated Config/Events.php (imports + listen block)", 'green');
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
        $pattern = "/use\s+{$escapedBase}\\\\\\{([\s\S]*?)\\};/m";

        if (preg_match($pattern, $content, $m)) {
            // existing grouped block: parse items
            $inner = trim($m[1]);
            $items = $this->splitImportItemsBlock($inner);

            if (! in_array($shortName, $items, true)) {
                $items[] = $shortName;
            }

            $items = $this->uniqueSorted($items);

            $newInner = "    " . implode(",\n    ", $items) . "\n";
            $replacement = "use {$baseNamespace}\\{\n{$newInner}};";

            $content = preg_replace($pattern, $replacement, $content, 1);
            return $content;
        }

        // grouped block not present: check if there are any single use statements under the same baseNamespace
        // collect existing single 'use App\Events\X;' lines and convert to grouped plus the new one
        $singlePattern = "/use\s+" . preg_quote($baseNamespace, '/') . "\\\\([A-Za-z0-9_\\\\]+)\s*;/m";
        preg_match_all($singlePattern, $content, $singles);

        $items = [];

        if (! empty($singles[1])) {
            foreach ($singles[1] as $s) {
                // Keep only the final short name (strip sub-namespaces)
                $parts = explode('\\', $s);
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
        $inner = "    " . implode(",\n    ", $items) . "\n";
        $group = "use {$baseNamespace}\\{\n{$inner}};";

        // Insert grouped block after the opening <?php and namespace (if present), otherwise at top
        if (preg_match('/namespace\s+[A-Za-z0-9_\\\\]+;\s*/', $content, $nm, PREG_OFFSET_CAPTURE)) {
            $insertPos = $nm[0][1] + strlen($nm[0][0]);
            $content = substr_replace($content, "\n{$group}\n", $insertPos, 0);
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
            if ($line === '') continue;
            $items[] = $line;
        }
        return $items;
    }

    protected function uniqueSorted(array $items): array
    {
        $items = array_values(array_unique($items));
        usort($items, function ($a, $b) {
            return strcasecmp($a, $b);
        });
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
        $pattern = '/listen\s*\(\s*\[(.*?)\]\s*\);/s';

        if (! preg_match($pattern, $content, $m)) {
            // no listen block found - append a new one at the end of imports area
            $newMap = [
                $eventShort => [$listenerShort],
            ];
            $newListen = $this->buildListenBlockFromMap($newMap);
            // place after grouped imports if present, otherwise append at file end
            if (preg_match('/use\s+App\\\\\\Events\\\\\{[\s\S]*?\};/m', $content, $mm, PREG_OFFSET_CAPTURE)) {
                $pos = $mm[0][1] + strlen($mm[0][0]);
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
            $evt = trim($p[1]); // short or namespaced, but in practice short
            $handlersBlock = trim($p[2]);

            // Extract handler short names
            preg_match_all('/([A-Za-z0-9_\\\\]+)::class/', $handlersBlock, $hs);
            $handlers = array_map(function ($h) {
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

        // Sort handlers and events
        foreach ($map as $k => $list) {
            $map[$k] = $this->uniqueSorted($list);
        }
        ksort($map, SORT_NATURAL | SORT_FLAG_CASE);

        // Rebuild listen block
        $newListen = $this->buildListenBlockFromMap($map);

        // Replace the old listen block in the content
        $content = preg_replace($pattern, $newListen, $content, 1);

        return $content;
    }

    protected function buildListenBlockFromMap(array $map): string
    {
        $lines = [];
        foreach ($map as $evt => $handlers) {
            $lines[] = "    {$evt}::class => [";
            foreach ($handlers as $h) {
                $lines[] = "        {$h}::class,";
            }
            $lines[] = "    ],";
        }

        $inner = implode("\n", $lines);

        return "listen([\n{$inner}\n]);";
    }

    protected function ensureDir(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0775, true);
        }
    }
}
