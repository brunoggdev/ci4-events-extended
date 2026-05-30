<?php

declare(strict_types=1);

namespace Brunoggdev\EventsExtended\Config;

use CodeIgniter\Config\BaseConfig;

class EventsExtended extends BaseConfig
{
    /**
     * Base path where event and listener classes are generated.
     * Defaults to app/Events/.
     */
    public string $eventsPath;

    /**
     * Whether events should throw on listener failure.
     * When false, errors are swallowed and logged or passed to $defaultErrorHandler.
     */
    public bool $throwOnError = false;

    /**
     * Default error handler called when a listener fails and no per-call handler is provided.
     * Receives ($exception, $event). If null, falls back to log_message.
     *
     * @var (callable(Throwable, object): void)|null
     */
    public $defaultErrorHandler;

    public function __construct()
    {
        $this->eventsPath = APPPATH . 'Events';

        parent::__construct();
    }
}
