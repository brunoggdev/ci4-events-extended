<?php

declare(strict_types=1);

namespace Brunoggdev\EventsExtended\Tests;

use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * @internal
 */
final class EventsHelperTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Events::removeAllListeners();
    }

    // -------------------------------------------------------------------------
    // listen()
    // -------------------------------------------------------------------------

    public function testListenRegistersInvokableListener(): void
    {
        $called = false;

        $listener = new class ($called) {
            public function __construct(private bool &$called)
            {
            }

            public function __invoke(object $event): void
            {
                $this->called = true;
            }
        };

        Events::on('SomeEvent', $listener);
        Events::trigger('SomeEvent', new stdClass());

        $this->assertTrue($called);
    }

    public function testListenRegistersHandleMethodListener(): void
    {
        $called = false;

        $listenerClass = new class ($called) {
            public function __construct(private bool &$called)
            {
            }

            public function handle(object $event): void
            {
                $this->called = true;
            }
        };

        // Simulate what listen() does for handle-method listeners
        Events::on('SomeEvent', [$listenerClass, 'handle']);
        Events::trigger('SomeEvent', new stdClass());

        $this->assertTrue($called);
    }

    public function testListenThrowsForInvalidListener(): void
    {
        $invalidClass = new class () {
            public function neitherInvokeNorHandle(): void
            {
            }
        };

        $this->expectException(RuntimeException::class);

        $listener = match (true) {
            method_exists($invalidClass, '__invoke') => $invalidClass,
            method_exists($invalidClass, 'handle')   => [$invalidClass, 'handle'],
            default                                  => throw new RuntimeException("Listener must be invokable or implement a 'handle' method"),
        };
    }

    // -------------------------------------------------------------------------
    // event()
    // -------------------------------------------------------------------------

    public function testEventDispatchesAndListenerIsCalledWithEventObject(): void
    {
        $received = null;

        Events::on(stdClass::class, static function (object $e) use (&$received) {
            $received = $e;
        });

        $payload      = new stdClass();
        $payload->foo = 'bar';

        event($payload);

        $this->assertSame($payload, $received);
    }

    public function testEventSwallowsExceptionByDefault(): void
    {
        Events::on(stdClass::class, static function () {
            throw new RuntimeException('Listener failed');
        });

        // Should not throw
        event(new stdClass());
        $this->assertTrue(true);
    }

    public function testEventCallsPerCallHandler(): void
    {
        Events::on(stdClass::class, static function () {
            throw new RuntimeException('Listener failed');
        });

        $caught = null;

        event(new stdClass(), static function (Throwable $e) use (&$caught) {
            $caught = $e;
        });

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertSame('Listener failed', $caught->getMessage());
    }

    public function testEventRethrowsWhenConfigured(): void
    {
        Events::on(stdClass::class, static function () {
            throw new RuntimeException('Rethrown');
        });

        config('EventsExtended')->throwOnError = true;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Rethrown');

        try {
            event(new stdClass());
        } finally {
            config('EventsExtended')->throwOnError = false;
        }
    }

    public function testPerCallHandlerTakesPrecedenceOverThrowOnError(): void
    {
        Events::on(stdClass::class, static function () {
            throw new RuntimeException('Handler wins');
        });

        config('EventsExtended')->throwOnError = true;

        $caught = null;

        try {
            event(new stdClass(), static function (Throwable $e) use (&$caught) {
                $caught = $e;
            });
        } finally {
            config('EventsExtended')->throwOnError = false;
        }

        // Should not have thrown — handler takes precedence
        $this->assertInstanceOf(RuntimeException::class, $caught);
    }
}
