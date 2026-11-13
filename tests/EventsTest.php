<?php

declare(strict_types=1);

namespace Brunoggdev\EventsExtended\Tests;

use Brunoggdev\EventsExtended\Events;
use Brunoggdev\EventsExtended\Tests\Support\BaseEvent;
use Brunoggdev\EventsExtended\Tests\Support\BaseListener;
use Brunoggdev\EventsExtended\Tests\Support\ChildEvent;
use Brunoggdev\EventsExtended\Tests\Support\DeferredListener;
use Brunoggdev\EventsExtended\Tests\Support\FailingConstructorListener;
use Brunoggdev\EventsExtended\Tests\Support\FailingListener;
use Brunoggdev\EventsExtended\Tests\Support\HandleListener;
use Brunoggdev\EventsExtended\Tests\Support\InvalidListener;
use Brunoggdev\EventsExtended\Tests\Support\InvokableListener;
use Brunoggdev\EventsExtended\Tests\Support\OtherEvent;
use Brunoggdev\EventsExtended\Tests\Support\Recorder;
use Brunoggdev\EventsExtended\Tests\Support\SilentListener;
use Brunoggdev\EventsExtended\Tests\Support\StoppingListener;
use Brunoggdev\EventsExtended\Tests\Support\Trackable;
use Brunoggdev\EventsExtended\Tests\Support\TrackableListener;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use RuntimeException;

/**
 * @internal
 */
final class EventsTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Events::removeAllListeners();
        Recorder::reset();
    }

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    public function testListenRegistersInvokableListener(): void
    {
        Events::listen([OtherEvent::class => [InvokableListener::class]]);

        Events::dispatch(new OtherEvent());

        $this->assertSame(['invokable:' . OtherEvent::class], Recorder::$calls);
    }

    public function testListenRegistersHandleMethodListener(): void
    {
        Events::listen([OtherEvent::class => HandleListener::class]);

        Events::dispatch(new OtherEvent());

        $this->assertSame(['handle:' . OtherEvent::class], Recorder::$calls);
    }

    public function testListenAcceptsCallables(): void
    {
        Events::listen([OtherEvent::class => static function (): void {
            Recorder::$calls[] = 'closure';
        }]);

        Events::dispatch(new OtherEvent());

        $this->assertSame(['closure'], Recorder::$calls);
    }

    public function testListenThrowsForInvalidListener(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Events::listen([OtherEvent::class => [InvalidListener::class]]);
    }

    public function testListenersAreInstantiatedLazilyAndOnce(): void
    {
        Events::listen([OtherEvent::class => [InvokableListener::class]]);

        $this->assertSame(0, Recorder::$constructed);

        Events::dispatch(new OtherEvent());
        Events::dispatch(new OtherEvent());

        $this->assertSame(1, Recorder::$constructed);
    }

    public function testListenKeepsGivenOrder(): void
    {
        Events::listen([OtherEvent::class => [TrackableListener::class, BaseListener::class]]);

        Events::dispatch(new OtherEvent());

        $this->assertSame(['trackable', 'base'], Recorder::$calls);
    }

    public function testSubscribeRegistersListenerForManyEvents(): void
    {
        Events::subscribe(InvokableListener::class, [OtherEvent::class, ChildEvent::class]);

        Events::dispatch(new OtherEvent());
        Events::dispatch(new ChildEvent());

        $this->assertSame(['invokable:' . OtherEvent::class, 'invokable:' . ChildEvent::class], Recorder::$calls);
        $this->assertSame(1, Recorder::$constructed);
    }

    // -------------------------------------------------------------------------
    // Dispatch by type
    // -------------------------------------------------------------------------

    public function testDispatchReachesClassThenParentsThenInterfaces(): void
    {
        Events::listen([
            Trackable::class  => [TrackableListener::class],
            BaseEvent::class  => [BaseListener::class],
            ChildEvent::class => [InvokableListener::class],
        ]);

        Events::dispatch(new ChildEvent());

        $this->assertSame(['invokable:' . ChildEvent::class, 'base', 'trackable'], Recorder::$calls);
    }

    public function testReturningFalseStopsRemainingTypes(): void
    {
        Events::listen([
            ChildEvent::class => [StoppingListener::class, InvokableListener::class],
            Trackable::class  => [TrackableListener::class],
        ]);

        Events::dispatch(new ChildEvent());

        $this->assertSame(['stopping'], Recorder::$calls);
    }

    public function testEventHelperDispatches(): void
    {
        Events::listen([Trackable::class => [TrackableListener::class]]);

        event(new OtherEvent());

        $this->assertSame(['trackable'], Recorder::$calls);
    }

    // -------------------------------------------------------------------------
    // Error handling
    // -------------------------------------------------------------------------

    public function testExceptionsPropagateByDefault(): void
    {
        Events::listen([OtherEvent::class => [FailingListener::class, TrackableListener::class]]);

        try {
            Events::dispatch(new OtherEvent());
            $this->fail('Expected exception was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('failing', $e->getMessage());
        }

        $this->assertSame([], Recorder::$calls);
    }

    public function testSilentListenerIsLoggedAndNextOneRuns(): void
    {
        Events::listen([OtherEvent::class => [SilentListener::class, TrackableListener::class]]);

        Events::dispatch(new OtherEvent());

        $this->assertSame(['trackable'], Recorder::$calls);
        $this->assertLogged('error', '[event:' . OtherEvent::class . '] [listener:' . SilentListener::class . '] silent');
    }

    public function testSilentListenerConstructorFailureIsLogged(): void
    {
        Events::listen([OtherEvent::class => [FailingConstructorListener::class, TrackableListener::class]]);

        Events::dispatch(new OtherEvent());

        $this->assertSame(['trackable'], Recorder::$calls);
    }

    public function testPlainTriggerAlsoGetsErrorHandling(): void
    {
        Events::listen([OtherEvent::class => [SilentListener::class, TrackableListener::class]]);

        Events::trigger(OtherEvent::class, new OtherEvent());

        $this->assertSame(['trackable'], Recorder::$calls);
    }

    // -------------------------------------------------------------------------
    // Deferral
    // -------------------------------------------------------------------------

    public function testDeferredListenerRunsImmediatelyWithoutScope(): void
    {
        Events::listen([OtherEvent::class => [DeferredListener::class]]);

        Events::dispatch(new OtherEvent());

        $this->assertSame(['deferred'], Recorder::$calls);
    }

    public function testDeferredListenerRunsAfterScope(): void
    {
        Events::listen([OtherEvent::class => [DeferredListener::class, TrackableListener::class]]);

        $result = Events::defer(static function (): string {
            Events::dispatch(new OtherEvent());
            Recorder::$calls[] = 'end of scope';

            return 'result';
        });

        $this->assertSame('result', $result);
        $this->assertSame(['trackable', 'end of scope', 'deferred'], Recorder::$calls);
    }

    public function testDeferredListenersAreDiscardedOnException(): void
    {
        Events::listen([OtherEvent::class => [DeferredListener::class]]);

        try {
            Events::defer(static function (): void {
                Events::dispatch(new OtherEvent());

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        Events::defer(static fn () => null);

        $this->assertSame([], Recorder::$calls);
    }

    public function testNestedScopesFlushOnlyAtOutermost(): void
    {
        Events::listen([OtherEvent::class => [DeferredListener::class]]);

        Events::defer(static function (): void {
            Events::defer(static fn () => Events::dispatch(new OtherEvent()));
            Recorder::$calls[] = 'inner done';
        });

        $this->assertSame(['inner done', 'deferred'], Recorder::$calls);
    }
}
