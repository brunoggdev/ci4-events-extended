# Events Extended for CodeIgniter 4

Lightweight, developer-friendly helpers and tooling that enhance the way you work with the CodeIgniter 4 Events system using a more OOP approach.
This package provides a cleaner, more expressive way to register and trigger events using simple event classes and invokable or `handle()`-based listeners.

---

## 📦 Installation

Install via Composer:
```bash
composer require brunoggdev/ci4-events-extended
```

The package auto-loads itself — no configuration required.

---

## 🚀 Features

- **Event classes instead of string names**
- **Extends CodeIgniter's `Events`** — same registry, `on()`/`trigger()` keep working
- **Dispatch by type** — listeners of the event class, its parents and its interfaces
- **`listen()` and `subscribe()`** — many listeners per event, or one listener for many events
- **Best-effort listeners** — mark a listener to log its failures and let the next one run
- **Deferred listeners** — run only after your transaction (or any scope) succeeds
- **Lazy listeners** — instantiated on first dispatch, once
- **Make commands** — `make:event`, `make:listener`
- **List command** — `events:list`, with effective listeners per event and duplicate warnings

---

## 🧩 Usage

Swap CodeIgniter's import for the package one (it extends it, so nothing else changes):
```php
use Brunoggdev\EventsExtended\Events;
```

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

Invokable, or with a `handle()` method:
```php
<?php

namespace App\Events\Listeners;

class SendWelcomeEmail
{
    public function __invoke(UserUpdated $event): void
    {
        // your logic here
    }
}
```

### 3. Register Listener(s)

The best place is `app/Config/Events.php`:
```php
Events::listen([
    UserUpdated::class => [
        SendWelcomeEmail::class,
        // YourSecondListener::class,
    ],
]);
```

One listener for many events that share no contract (when they do, register on their interface instead, see [Dispatch by Type](#-dispatch-by-type)):
```php
Events::subscribe(StoreTrackingEvent::class, [
    ExamStarted::class,
    ExamFinished::class,
]);
```

> `listen()` runs listeners in the order given. `subscribe()` uses `Events::PRIORITY_NORMAL` unless you pass a priority. Returning `false` from a listener stops every remaining listener.

### 4. Dispatch the Event
```php
Events::dispatch(new UserUpdated($user));
// or
event(new UserUpdated($user));
```

---

## 🧬 Dispatch by Type

An event also reaches the listeners registered for its parent classes and interfaces, in this order:

1. The event class
2. Its parent classes, closest first
3. Its interfaces (no guaranteed order between them)

Group events with your own interfaces and register the shared listener once:
```php
class ExamFinished implements Trackable {}
class PaymentConfirmed implements Trackable {}

Events::listen([
    Trackable::class => [StoreTrackingEvent::class],
]);
```

> A listener registered on both the class and one of its interfaces runs twice. When order matters, register on the event class.

---

## ⚠️ Error Handling

Exceptions propagate, as in CodeIgniter: a failing listener stops the remaining ones and the exception reaches the caller.

Mark best-effort listeners (logging, webhooks, tracking) with `ShouldFailSilently`. Their failures, constructor included, are logged as errors with the exception in context, and the next listener still runs:
```php
use Brunoggdev\EventsExtended\Contracts\ShouldFailSilently;

class StoreLogEvent implements ShouldFailSilently
{
    public function __invoke(Loggable $event): void { /* ... */ }
}
```

---

## ⏳ Deferred Listeners

Listeners implementing `ShouldDefer` wait until the outermost `Events::defer()` scope returns. If it throws, they are discarded. Outside a scope they run immediately.
```php
use Brunoggdev\EventsExtended\Contracts\ShouldDefer;

class SendWebhook implements ShouldDefer
{
    public function __invoke(PaymentUpdated $event): void { /* HTTP call */ }
}
```

Wrap your whole transaction (if your app already has a transaction helper, put its body inside `Events::defer()` once):
```php
$db = db_connect();
$db->transException(true); // a failed transaction must throw

Events::defer(function () use ($db, $payment) {
    $db->transStart();
    model(PaymentModel::class)->save($payment);
    event(new PaymentUpdated($payment)); // SendWebhook is queued
    $db->transComplete();
});
// committed: SendWebhook runs here
```

> The package doesn't know about the database: `defer()` only knows the callback returned. Make sure a failed transaction throws, otherwise deferred listeners run after a rollback. A `ShouldDefer` listener inside a transaction opened without `defer()` runs immediately. A deferred listener that throws after the commit makes `defer()` throw, unless it is also `ShouldFailSilently`.

---

## 🎮 CLI Commands

### `make:event`

Generates an event class in `{namespace}\Events` (subfolders allowed):
```bash
php spark make:event UserUpdated
php spark make:event User/Registered --namespace 'App\Domain'
```

### `make:listener`

Generates an invokable listener in `{namespace}\Events\Listeners`, creates the event when missing, and registers both in `Events::listen([...])` in `app/Config/Events.php` (swapping the `Events` import for the package one). The event can be a name inside the Events folder or a FQCN:
```bash
php spark make:listener SendWelcomeEmail UserUpdated
php spark make:listener StoreLogEvent 'App\Interfaces\Loggable'
```

Both accept `--namespace` (default `APP_NAMESPACE`) and `--force`.

### `events:list`

Lists every registered event and its listeners, with priority and markers (`defer`, `silent`):
```bash
php spark events:list
php spark events:list --listener StoreLogEvent
php spark events:list --event 'App\Events\PaymentUpdated'
```

`--event` shows what an event actually reaches, in order: its class, parents and interfaces. Short names work for events registered directly; use the FQCN for events reached only through a parent or interface. It also warns when a listener would run more than once for the same event (e.g. registered on both the class and one of its interfaces).

---

## 📋 Requirements

- PHP 8.1+
- CodeIgniter 4.3+

---

## ⬆️ Upgrading from v1

- Import `Brunoggdev\EventsExtended\Events` in `app/Config/Events.php` and replace `listen([...])` with `Events::listen([...])`.
- Listener exceptions now propagate by default, as in CodeIgniter. Mark listeners that must not break the flow with `ShouldFailSilently`.
- Events now also reach listeners of their parent classes and interfaces.
- `make:event`/`make:listener` are now standard CI4 generators (`--namespace`, `--force`); the `eventsPath` setting is gone.
- `event()` no longer takes `$onError`; catch around the call or use `ShouldFailSilently`.
- The `EventsExtended` config and `events:publish` were removed, along with `throwOnError` and `defaultErrorHandler`. Silent failures are logged with the exception in context, so route them with a logger handler.
