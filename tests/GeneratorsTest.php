<?php

declare(strict_types=1);

namespace Brunoggdev\EventsExtended\Tests;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\StreamFilterTrait;

/**
 * @internal
 */
final class GeneratorsTest extends CIUnitTestCase
{
    use StreamFilterTrait;

    private string $eventsConfig;
    private string $originalConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eventsConfig   = APPPATH . 'Config/Events.php';
        $this->originalConfig = (string) file_get_contents($this->eventsConfig);
    }

    protected function tearDown(): void
    {
        file_put_contents($this->eventsConfig, $this->originalConfig);
        $this->removeDir(APPPATH . 'Events');

        parent::tearDown();
    }

    public function testMakeEventGeneratesClass(): void
    {
        command('make:event User/Registered');

        $file = APPPATH . 'Events/User/Registered.php';

        $this->assertFileExists($file);
        $this->assertStringContainsString("namespace App\\Events\\User;\n\nclass Registered", (string) file_get_contents($file));
    }

    public function testMakeListenerGeneratesListenerEventAndRegistration(): void
    {
        command('make:listener SendWelcomeEmail UserRegistered');

        $listener = (string) file_get_contents(APPPATH . 'Events/Listeners/SendWelcomeEmail.php');
        $config   = (string) file_get_contents($this->eventsConfig);

        $this->assertStringContainsString('namespace App\\Events\\Listeners;', $listener);
        $this->assertStringContainsString('use App\\Events\\UserRegistered;', $listener);
        $this->assertStringContainsString('public function __invoke(UserRegistered $event): void', $listener);
        $this->assertFileExists(APPPATH . 'Events/UserRegistered.php');

        $this->assertStringContainsString('use Brunoggdev\\EventsExtended\\Events;', $config);
        $this->assertStringNotContainsString('use CodeIgniter\\Events\\Events;', $config);
        $this->assertMatchesRegularExpression('/Events::listen\(\[\s+UserRegistered::class => \[\s+SendWelcomeEmail::class,/', $config);
    }

    public function testMakeListenerMergesIntoExistingBlock(): void
    {
        command('make:listener SendWelcomeEmail OrderPlaced');
        command('make:listener NotifyAdmin OrderPlaced');

        $config = (string) file_get_contents($this->eventsConfig);

        $this->assertSame(1, substr_count($config, 'Events::listen(['));
        $this->assertMatchesRegularExpression('/OrderPlaced::class => \[\s+SendWelcomeEmail::class,\s+NotifyAdmin::class,/', $config);
    }

    public function testMakeListenerAcceptsFqcnAndPlacesEventAlongside(): void
    {
        command('make:listener App/Events/Billing/Listeners/ChargeCard InvoiceIssued');

        $listener = (string) file_get_contents(APPPATH . 'Events/Billing/Listeners/ChargeCard.php');

        $this->assertStringContainsString('use App\\Events\\Billing\\InvoiceIssued;', $listener);
        $this->assertFileExists(APPPATH . 'Events/Billing/InvoiceIssued.php');
    }

    public function testMakeListenerLeavesConfigUntouchedWhenGenerationFails(): void
    {
        command('make:listener Broken SomethingHappened --namespace Undefined');

        $this->assertSame($this->originalConfig, file_get_contents($this->eventsConfig));
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (array_diff((array) scandir($dir), ['.', '..']) as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }

        rmdir($dir);
    }
}
