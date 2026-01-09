# Events Extended for CodeIgniter 4

Lightweight, developer-friendly helpers and tooling that enhance the way you work with the CodeIgniter4 Events system to a more OOP approach.
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
- **Optional make commands**
  - `make:event`
  - `make:listener`
- Zero configuration, fully plug-and-play

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

The best place to register your listeners is probably `app/Config/Events.php`:

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

>The listeners will be executed in the order they're indexed in the array and, as we're using the udnerlying CI4 events system, you can also return `false` from your listener method to stop subsequent events from being executed.

### 4. Trigger the Event

You can simply call the event() helper wherever you like and pass in the desired event object and it will take care of the rest!

```php
event(new UserUpdated($user));
```

---

## 🎮 CLI Commands

Those commands are available when you run `php spark` from the command line. They'll update your `app/Config/Events.php` file for you.

### `make:event`

Creates a skeleton event class.

```bash
php spark make:event UserUpdated
```

### `make:listener`

Creates a listener class (invokable by default). You can also specify the event class name as a second argument.

```bash
php spark make:listener SendWelcomeEmail UserUpdated
```

---

## 🧠 How It Works

When a listener string like:

```php
\App\Listeners\SendWelcomeEmail::class
```

is registered, the helper:

1. Creates an instance if `__invoke` exists  
2. Registers `[ClassName::class, 'handle']` if `handle()` exists  
3. Throws otherwise  

`event($object)` dispatches using the event object's class name.

