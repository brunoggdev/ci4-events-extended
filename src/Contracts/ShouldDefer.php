<?php

declare(strict_types=1);

namespace Brunoggdev\EventsExtended\Contracts;

/**
 * Marks a listener to run only after the outermost Events::defer() scope
 * returns without an exception. Outside a scope it runs immediately.
 */
interface ShouldDefer
{
}
