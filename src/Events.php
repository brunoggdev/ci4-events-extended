<?php

declare(strict_types=1);

namespace Brunoggdev\EventsExtended;

use Brunoggdev\EventsExtended\Contracts\ShouldDefer;
use Brunoggdev\EventsExtended\Contracts\ShouldFailSilently;
use Closure;
use CodeIgniter\Events\Events as BaseEvents;
use InvalidArgumentException;
use Throwable;
use WeakMap;

/**
 * CodeIgniter's Events with class-based events and listeners on top.
 * Shares the same listener registry, so on() and trigger() keep working as usual.
 */
class Events extends BaseEvents
{
    /**
     * Cached dispatch types per event class: the class, its parents, then its interfaces.
     *
     * @var array<class-string, list<class-string>>
     */
    private static array $types = [];

    /**
     * How many defer() scopes are currently open.
     */
    private static int $deferDepth = 0;

    /**
     * Listeners waiting for the outermost defer() scope to finish.
     *
     * @var list<Closure(): mixed>
     */
    private static array $deferred = [];

    /**
     * What each wrapped listener stands for, used by describe().
     *
     * @var WeakMap<Closure, array{listener: string, defer: bool, silent: bool}>|null
     */
    private static ?WeakMap $wrapped = null;

    /**
     * Registers listeners for events. Listeners run in the order given.
     *
     * @param array<string, array<int, callable|class-string>|callable|class-string> $map Event class (or name) => listener(s)
     *
     * @example
     * ```php
     * Events::listen([
     *     UserRegistered::class => [
     *         SendWelcomeEmail::class,
     *         QueueEventWebhook::class,
     *     ],
     * ]);
     * ```
     */
    public static function listen(array $map): void
    {
        foreach ($map as $event => $listeners) {
            $listeners = is_array($listeners) && ! is_callable($listeners) ? array_values($listeners) : [$listeners];

            foreach ($listeners as $index => $listener) {
                static::on($event, self::wrap($listener), $index + 1);
            }
        }
    }

    /**
     * Registers a single listener for many events.
     *
     * @param callable|class-string $listener
     * @param list<string>          $events   Event classes (or names)
     */
    public static function subscribe(callable|string $listener, array $events, int $priority = self::PRIORITY_NORMAL): void
    {
        $callback = self::wrap($listener);

        foreach ($events as $event) {
            static::on($event, $callback, $priority);
        }
    }

    /**
     * Dispatches an event object to the listeners of its class, then of
     * its parent classes, then of its interfaces. A listener returning
     * `false` stops every remaining listener.
     */
    public static function dispatch(object $event): void
    {
        foreach (self::typesOf($event) as $type) {
            if (static::trigger($type, $event) === false) {
                return;
            }
        }
    }

    /**
     * Runs the callback holding back ShouldDefer listeners until it returns.
     * On success (outermost scope only) they run in order; on exception
     * they are discarded and the exception is rethrown.
     *
     * Wrap your whole transaction with it, and make sure a failed transaction
     * throws (e.g. `$db->transException(true)`), otherwise deferred listeners
     * would run after a rollback.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public static function defer(callable $callback): mixed
    {
        self::$deferDepth++;

        try {
            $result = $callback();
        } catch (Throwable $e) {
            if (--self::$deferDepth === 0) {
                self::$deferred = [];
            }

            throw $e;
        }

        if (--self::$deferDepth === 0) {
            $queue          = self::$deferred;
            self::$deferred = [];

            foreach ($queue as $run) {
                $run();
            }
        }

        return $result;
    }

    /**
     * Every registered listener per event name, in execution order.
     * Listeners not registered through listen()/subscribe() are described by their callable.
     *
     * @return array<string, list<array{listener: string, priority: int, defer: bool, silent: bool}>>
     */
    public static function describe(): array
    {
        static::initialize();

        $described = [];

        foreach (array_keys(static::$listeners) as $name) {
            $callbacks  = static::listeners($name);
            $priorities = static::$listeners[$name][1];

            foreach ($callbacks as $i => $callback) {
                $meta = $callback instanceof Closure ? self::$wrapped[$callback] ?? null : null;

                $described[$name][] = [
                    'listener' => $meta['listener'] ?? self::nameOf($callback),
                    'priority' => $priorities[$i],
                    'defer'    => $meta['defer'] ?? false,
                    'silent'   => $meta['silent'] ?? false,
                ];
            }
        }

        return $described;
    }

    /**
     * Types an event of the given class is dispatched to, in order:
     * the class, its parents (closest first), then its interfaces.
     *
     * @param class-string $class
     *
     * @return list<class-string>
     */
    public static function typesFor(string $class): array
    {
        return self::$types[$class] ??= [
            $class,
            ...array_values(class_parents($class)),
            ...array_values(class_implements($class)),
        ];
    }

    /**
     * @return list<class-string>
     */
    private static function typesOf(object $event): array
    {
        return self::typesFor($event::class);
    }

    /**
     * Wraps a listener with lazy instantiation, deferral and per-listener error handling.
     * Class-string listeners must be invokable or implement a `handle()` method.
     *
     * @param callable|class-string $listener
     */
    private static function wrap(callable|string $listener): Closure
    {
        $class    = self::classOf($listener);
        $callback = null;

        if (is_string($listener) && $class === $listener) {
            if (! method_exists($class, '__invoke') && ! method_exists($class, 'handle')) {
                throw new InvalidArgumentException("Listener '{$class}' must be invokable or implement a 'handle' method.");
            }
        } elseif (is_callable($listener)) {
            $callback = $listener;
        } else {
            throw new InvalidArgumentException("Listener '{$listener}' is neither callable nor an existing class.");
        }

        $defer  = $class !== null && is_a($class, ShouldDefer::class, true);
        $silent = $class !== null && is_a($class, ShouldFailSilently::class, true);

        $resolve = static function () use (&$callback, $class): callable {
            return $callback ??= method_exists($class, '__invoke') ? new $class() : [new $class(), 'handle'];
        };

        $wrapper = static function (mixed ...$arguments) use ($resolve, $defer, $silent, $class): mixed {
            $run = static fn (): mixed => self::invoke($resolve, $arguments, $silent, $class);

            if ($defer && self::$deferDepth > 0) {
                self::$deferred[] = $run;

                return true;
            }

            return $run();
        };

        self::$wrapped ??= new WeakMap();
        self::$wrapped[$wrapper] = [
            'listener' => $class ?? self::nameOf($listener),
            'defer'    => $defer,
            'silent'   => $silent,
        ];

        return $wrapper;
    }

    /**
     * Readable name for a callable.
     */
    private static function nameOf(mixed $callback): string
    {
        return match (true) {
            $callback instanceof Closure => 'Closure',
            is_string($callback)         => $callback,
            is_object($callback)         => $callback::class,
            is_array($callback)          => (is_object($callback[0]) ? $callback[0]::class : $callback[0]) . '::' . $callback[1],
            default                      => get_debug_type($callback),
        };
    }

    /**
     * Exceptions propagate, as in CodeIgniter. ShouldFailSilently listeners log
     * them instead (constructor failures included) and let the next one run.
     *
     * @param Closure(): callable $resolve
     * @param list<mixed>         $arguments
     */
    private static function invoke(Closure $resolve, array $arguments, bool $silent, ?string $class): mixed
    {
        if (! $silent) {
            return $resolve()(...$arguments);
        }

        try {
            return $resolve()(...$arguments);
        } catch (Throwable $e) {
            $event = isset($arguments[0]) && is_object($arguments[0]) ? $arguments[0]::class : 'unknown';

            log_message('error', "[event:{$event}] [listener:" . ($class ?? 'callable') . '] ' . $e->getMessage(), ['exception' => $e]);

            return null;
        }
    }

    /**
     * Class a listener belongs to, when there is one to read markers from.
     */
    private static function classOf(callable|string $listener): ?string
    {
        return match (true) {
            is_string($listener) && class_exists($listener) => $listener,
            $listener instanceof Closure                    => null,
            is_object($listener)                            => $listener::class,
            is_array($listener) && is_object($listener[0])  => $listener[0]::class,
            is_array($listener)                             => $listener[0],
            default                                         => null,
        };
    }
}
