<?php

declare(strict_types=1);

namespace Brunoggdev\EventsExtended\Tests\Support;

use Brunoggdev\EventsExtended\Contracts\ShouldDefer;
use Brunoggdev\EventsExtended\Contracts\ShouldFailSilently;
use RuntimeException;

final class Recorder
{
    /**
     * @var list<string>
     */
    public static array $calls = [];

    public static int $constructed = 0;

    public static function reset(): void
    {
        self::$calls       = [];
        self::$constructed = 0;
    }
}

interface Trackable
{
}

class BaseEvent
{
}

final class ChildEvent extends BaseEvent implements Trackable
{
}

final class OtherEvent implements Trackable
{
}

final class InvokableListener
{
    public function __construct()
    {
        Recorder::$constructed++;
    }

    public function __invoke(object $event): void
    {
        Recorder::$calls[] = 'invokable:' . $event::class;
    }
}

final class HandleListener
{
    public function handle(object $event): void
    {
        Recorder::$calls[] = 'handle:' . $event::class;
    }
}

final class BaseListener
{
    public function __invoke(object $event): void
    {
        Recorder::$calls[] = 'base';
    }
}

final class TrackableListener
{
    public function __invoke(object $event): void
    {
        Recorder::$calls[] = 'trackable';
    }
}

final class StoppingListener
{
    public function __invoke(object $event): bool
    {
        Recorder::$calls[] = 'stopping';

        return false;
    }
}

final class FailingListener
{
    public function __invoke(object $event): void
    {
        throw new RuntimeException('failing');
    }
}

final class SilentListener implements ShouldFailSilently
{
    public function __invoke(object $event): void
    {
        throw new RuntimeException('silent');
    }
}

final class DeferredListener implements ShouldDefer
{
    public function __invoke(object $event): void
    {
        Recorder::$calls[] = 'deferred';
    }
}

final class FailingConstructorListener implements ShouldFailSilently
{
    public function __construct()
    {
        throw new RuntimeException('constructor');
    }

    public function __invoke(object $event): void
    {
    }
}

final class InvalidListener
{
}
