<?php

declare(strict_types=1);

namespace Brunoggdev\EventsExtended\Contracts;

/**
 * Marks a best-effort listener: its exceptions are logged instead of
 * rethrown, and the next listener still runs.
 */
interface ShouldFailSilently
{
}
