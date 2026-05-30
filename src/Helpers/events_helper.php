<?php

use CodeIgniter\Events\Events;

if (! function_exists('listen')) {
    /**
     * Listens to a set of events and executes a set of listeners
     *
     * @param array $map An array of event names as keys and an array of listeners as values
     *
     * @example
     * ```php
     * listen([
     *     UserRegistered::class => [
     *         SendWelcomeEmail::class,
     *         QueueEventWebhook::class
     *     ],
     * ]);
     * ```
     */
    function listen(array $map): void
    {
        foreach ($map as $event => $listeners) {
            foreach ((array) $listeners as $index => $listener) {
                if (is_string($listener) && class_exists($listener)) {
                    $listener = match (true) {
                        method_exists($listener, '__invoke') => new $listener(),
                        method_exists($listener, 'handle')   => [$listener, 'handle'],
                        default                              => throw new RuntimeException("Listener '{$listener}' must be invokable or implement a 'handle' method"),
                    };
                }

                Events::on($event, $listener, $index + 1);
            }
        }
    }
}

if (! function_exists('event')) {
    /**
     * Triggers an event and runs all registered listeners for it.
     *
     * By default, listener exceptions are swallowed and logged unless
     * `EventsExtended::$throwOnError` is set to `true`.
     *
     * A default error handler can be set via `EventsExtended::$defaultErrorHandler`
     * and will be called with `($exception, $event)` when a listener fails.
     *
     * A per-call handler can be provided as the second argument, taking precedence
     * over the default handler. When a handler is provided, the exception is always
     * swallowed after the handler runs regardless of `throwOnError`.
     *
     * @param object                           $event   The event object to dispatch.
     * @param callable(Throwable, object)|null $onError Optional per-call error handler.
     */
    function event(object $event, ?callable $onError = null): void
    {
        try {
            Events::trigger($event::class, $event);
        } catch (Throwable $e) {
            $config  = config('EventsExtended');
            $handler = $onError ?? $config->defaultErrorHandler;

            if ($handler !== null) {
                $handler($e, $event);

                return;
            }

            if ($config->throwOnError) {
                throw $e;
            }

            log_message('error', '[event:' . $event::class . '] ' . $e->getMessage());
        }
    }
}
