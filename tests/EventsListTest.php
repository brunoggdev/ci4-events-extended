<?php

declare(strict_types=1);

namespace Brunoggdev\EventsExtended\Tests;

use Brunoggdev\EventsExtended\Commands\EventsList;
use Brunoggdev\EventsExtended\Events;
use Brunoggdev\EventsExtended\Tests\Support\BaseEvent;
use Brunoggdev\EventsExtended\Tests\Support\BaseListener;
use Brunoggdev\EventsExtended\Tests\Support\ChildEvent;
use Brunoggdev\EventsExtended\Tests\Support\DeferredListener;
use Brunoggdev\EventsExtended\Tests\Support\OtherEvent;
use Brunoggdev\EventsExtended\Tests\Support\SilentListener;
use Brunoggdev\EventsExtended\Tests\Support\Trackable;
use Brunoggdev\EventsExtended\Tests\Support\TrackableListener;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\StreamFilterTrait;

/**
 * @internal
 */
final class EventsListTest extends CIUnitTestCase
{
    use StreamFilterTrait;

    protected function setUp(): void
    {
        parent::setUp();

        Events::initialize();
        Events::removeAllListeners();

        Events::listen([
            ChildEvent::class => [SilentListener::class, TrackableListener::class],
            BaseEvent::class  => [BaseListener::class],
            Trackable::class  => [TrackableListener::class, DeferredListener::class],
        ]);
        Events::on('plain', static fn () => null);
    }

    public function testDescribeListsListenersWithMarkers(): void
    {
        $described = Events::describe();

        $this->assertSame(
            ['listener' => SilentListener::class, 'priority' => 1, 'defer' => false, 'silent' => true],
            $described[ChildEvent::class][0],
        );
        $this->assertTrue($described[Trackable::class][1]['defer']);
        $this->assertSame('Closure', $described['plain'][0]['listener']);
    }

    public function testListShowsEveryRegistration(): void
    {
        $this->runCommand([]);

        $output = $this->getStreamFilterBuffer();

        $this->assertStringContainsString('plain', $output);
        $this->assertStringContainsString(BaseListener::class, $output);
        $this->assertStringContainsString('silent', $output);
    }

    public function testListFiltersByListener(): void
    {
        $this->runCommand(['listener' => 'BaseListener']);

        $output = $this->getStreamFilterBuffer();

        $this->assertStringContainsString(BaseListener::class, $output);
        $this->assertStringNotContainsString(DeferredListener::class, $output);
    }

    public function testEventShowsEffectiveListenersInOrder(): void
    {
        $this->runCommand(['event' => 'ChildEvent']);

        $output = $this->getStreamFilterBuffer();

        $silent   = strpos($output, SilentListener::class);
        $base     = strpos($output, BaseListener::class);
        $deferred = strpos($output, DeferredListener::class);

        $this->assertNotFalse($silent);
        $this->assertLessThan($base, $silent);
        $this->assertLessThan($deferred, $base);
    }

    public function testEventReachedOnlyThroughInterfaceNeedsFqcn(): void
    {
        $this->runCommand(['event' => OtherEvent::class]);

        $this->assertStringContainsString(DeferredListener::class, $this->getStreamFilterBuffer());
    }

    public function testWarnsAboutDuplicates(): void
    {
        $this->runCommand(['event' => ChildEvent::class]);

        $this->assertStringContainsString(
            'Warning: ' . TrackableListener::class . ' runs 2 times for ' . ChildEvent::class,
            $this->getStreamFilterBuffer(),
        );
    }

    /**
     * @param array<string, string> $params
     */
    private function runCommand(array $params): void
    {
        (new EventsList(service('logger'), service('commands')))->run($params);
    }
}
