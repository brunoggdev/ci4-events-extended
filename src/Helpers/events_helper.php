<?php

use CodeIgniter\Events\Events;

if (! function_exists('listen')) {
    /**
     * Listens to a set of events and executes a set of listeners
     * @param array $map An array of event names as keys and an array of listeners as values
     * @example 
     * ```php
     * listen([
     *     UserRegistered::class => [
     *         SendWelcomeEmail::class,
     *         QueueEventWebhook::class
     *     ],
     * ]);
     * ```
     *
     */
    function listen(array $map): void
    {
        foreach ($map as $event => $listeners) {
            foreach ((array) $listeners as $listener) {

                if (is_string($listener) && class_exists($listener)) {
                    $listener = match (true) {
                        method_exists($listener, '__invoke') => new $listener(),
                        method_exists($listener, 'handle')   => [$listener, 'handle'],
                        default => throw new RuntimeException("Listener '$listener' must be invokable or implement a 'handle' method"),
                    };
                }

                Events::on($event, $listener);
            }
        }
    }
}


if (! function_exists('event')) {

    /**
     * Triggers an event
     * @param object $event The event object
     */
    function event(object $event): void
    {
        Events::trigger($event::class, $event);
    }
}
