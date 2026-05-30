# Events Extended for CodeIgniter 4

Lightweight, developer-friendly helpers and tooling that enhance the way you work with the CodeIgniter 4 Events system using a more OOP approach.
This package provides a cleaner, more expressive way to register and trigger events using simple event classes and invokable or `handle()`-based listeners.

---

## 📦 Installation

Install via Composer:
```bash
composer require brunoggdev/ci4-events-extended
```

The package auto-loads itself — no configuration or publishing required.

---

## 🚀 Features

- **Event classes instead of string names**
- **Automatic listener resolution**
  - Invokable listeners (`__invoke`)
  - Or listeners with a `handle()` method
- **Simple `listen()` helper** to register multiple listeners at once
- **Simple `event()` helper** to dispatch event objects
- **Configurable error handling** for listener failures
- **Optional make commands**
  - `make:event`
  - `make:listener`
- **Publish command** to customize default configuration
  - `events:publish`

---

## 🧩 Usage

### 1. Create an Event Class
```php
<?php

namespace App\Events;

use App\Entities\User;

class UserUpdated
{
    public function __construct(
        public readonly User $user,
    ) {}
}
```

### 2. Create a Listener

#### Invokable listener:
```php
<?php

namespace App\Listeners;

class SendWelcomeEmail
{
    public function __invoke(UserUpdated $event)
    {
        // your logic here
    }
}
```

#### Or listener with `handle()`:
```php
<?php

namespace App\Listeners;

class SendWelcomeEmail
{
    public function handle(UserUpdated $event)
    {
        // your logic
    }
}
```

### 3. Register Listener(s)

The best place to register your listeners is `app/Config/Events.php`:
```php
listen([
    UserUpdated::class => [
        SendWelcomeEmail::class,
        // YourSecondListener::class,
        // YourThirdListener::class,
        // ...
    ],

    YourOtherEvent::class => [
        AnotherListener::class,
    ],
]);
```

> Listeners are executed in the order they are indexed. As we use the underlying CI4 events system, returning `false` from a listener stops subsequent listeners from being executed.

### 4. Trigger the Event
```php
event(new UserUpdated($user));
```

---

## 🧠 How It Works

When a listener string like `\App\Listeners\SendWelcomeEmail::class` is registered, the helper:

1. Creates an instance if `__invoke` exists
2. Registers `[ClassName::class, 'handle']` if `handle()` exists
3. Throws otherwise

`event($object)` dispatches using the event object's fully qualified class name as the event identifier.

---

## ⚠️ Error Handling

By default, listener exceptions are **swallowed and logged** so a failing listener never interferes with your application's main workflow.

### Per-call handler

You can provide a callable as a second argument to `event()` to handle errors on a per-call basis. When provided, it takes precedence over the default handler and the exception is always swallowed after it runs:
```php
event(new UserUpdated($user), fn($e, $event) => report($e));
```

### Global configuration

To customize the default behavior, publish the config file:
```bash
php spark events:publish
```

This creates `app/Config/EventsExtended.php` where you can configure:
```php
<?php

namespace Config;

use Brunoggdev\EventsExtended\Config\EventsExtended as BaseEventsExtended;

class EventsExtended extends BaseEventsExtended
{
    /**
     * Base path where event and listener classes are generated.
     * Defaults to app/Events/.
     */
    public string $eventsPath;

    /**
     * Whether events should throw on listener failure.
     * When false, errors are passed to $defaultErrorHandler or logged.
     */
    public bool $throwOnError = false;

    /**
     * Default error handler called when a listener fails and no per-call
     * handler is provided. Receives (Throwable $e, object $event): void.
     * If null, falls back to log_message('error', ...).
     */
    public $defaultErrorHandler = null;
}
```

### Behavior matrix

| `throwOnError` | `$onError` param | Result |
|---|---|---|
| `false` (default) | not passed | log as error and swallow |
| `false` (default) | passed | call handler and swallow |
| `true` | not passed | rethrow |
| `true` | passed | call handler and swallow |

---

## 🎮 CLI Commands

### `make:event`

Creates a skeleton event class:
```bash
php spark make:event UserUpdated
```

### `make:listener`

Creates a listener class (invokable by default). Optionally specify the event class name as a second argument:
```bash
php spark make:listener SendWelcomeEmail UserUpdated
```

### `events:publish`

Publishes the `EventsExtended` config file into your app's `Config/` folder for customization:
```bash
php spark events:publish
```

---

## 📋 Requirements

- PHP 8.1+
- CodeIgniter 4.x