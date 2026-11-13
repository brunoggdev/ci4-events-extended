<?php

use Brunoggdev\EventsExtended\Events;

if (! function_exists('event')) {
    /**
     * Dispatches an event object. Shortcut for Events::dispatch().
     */
    function event(object $event): void
    {
        Events::dispatch($event);
    }
}
