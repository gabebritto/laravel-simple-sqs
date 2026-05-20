# Laravel Simple SQS Messenger

This Laravel package is designed to facilitate asynchronous microservices communication via AWS SQS using **pure JSON** payloads (without Laravel's native PHP serialization).

With this library, you can integrate and map incoming external JSON messages directly to **standard Laravel Jobs**, maintaining full compatibility with Laravel's native queue workers (`php artisan queue:work`).

---

## JSON Payload Structure

All messages sent and received must strictly adhere to this flat format:

```json
{
  "job": "external-event",
  "data": {
    "event": "OrderCreated",
    "order_id": 123,
    "total": 150.00
  }
}
```

---

## Installation & Setup

### 1. Requirements
Ensure that the `sqs` queue driver and the AWS SDK are already configured in your host Laravel application (`config/queue.php`).

### 2. Registering the Package
In your main Laravel application's `composer.json`, add the local path repository:

```json
"repositories": [
    {
        "type": "path",
        "url": "../laravel-simple-sqs"
    }
],
```

Then, install the package using:
```bash
composer require gabebritto/laravel-simple-sqs
```

### 3. Publish Configurations
Publish the package configuration file using the artisan publish command:

```bash
php artisan vendor:publish --tag=sqs-messenger-config
```

This will generate the `config/sqs-messenger.php` file:

```php
<?php

return [
    // The default SQS queue URL. If null, it falls back to config/queue.php configurations.
    'queue_url' => env('SQS_MESSENGER_QUEUE_URL'),

    // Map the incoming JSON 'job' alias keys to their respective Job classes
    'handlers' => [
        'external-event' => \App\Jobs\ConsolidateJob::class,
    ],
];
```

---

## How it Works

The package provides the `Gabebritto\LaravelSimpleSqs\Traits\SqsJsonJob` trait, which automates serialization (dispatching) and deserialization/hydration (consuming) of the queue messages.

### 1. Publisher (Dispatching Messages)

You have three clean and robust ways to dispatch raw JSON messages to the queue:

#### Option A: Static Dispatching via the Job class (Recommended)
The `SqsJsonJob` trait automatically resolves the mapped alias from the configuration file and sends the payload in pure JSON format:

```php
use App\Jobs\ConsolidateJob;

ConsolidateJob::dispatchSqsJson(
    payload: [
        'event' => 'OrderCreated',
        'order_id' => 123,
        'total' => 150.00
    ]
);
```

#### Option B: Dynamic Instantiation & Publishing
If you prefer to instantiate the Job and define its public properties, the trait extracts the public properties of the class (filtering out Laravel's internal properties) and sends them as the JSON payload. `event` is just a standard property of the job:

```php
$job = new ConsolidateJob();
$job->orderId = 123;
$job->total = 150.00;
$job->event = 'OrderCreated'; // Set event directly as a normal property

$job->publish(); // Dispatches the message to SQS
```

#### Option C: Directly via the `SqsPublisher` Facade
You can also dispatch arbitrary payloads without having a local Job class or a configuration mapping defined:

```php
use Gabebritto\LaravelSimpleSqs\Facades\SqsPublisher;

SqsPublisher::dispatch(
    jobAlias: 'external-event',
    payload: [
        'event' => 'OrderCreated',
        'order_id' => 123,
        'total' => 150.00
    ]
);
```

---

### 2. Consumer (Receiving Messages / Worker)

Your Consumer Job can be a standard Laravel Job (implementing `ShouldQueue` and using traits like `InteractsWithQueue` and `Queueable`), simply by importing the `SqsJsonJob` trait.

The trait takes care of the following actions automatically upon receiving the message:
- Intercepts the native Laravel queue worker execution.
- Extracts and populates the `$this->payload` property (which is the direct content of `"data"`).
- **Auto-hydrates properties**: Matches keys in the payload to public properties declared on the class (supports both `camelCase` and `snake_case` properties, including `event` if present in the payload).
- Links the raw SQS job instance to enable standard job manipulation methods (e.g. `$this->release()`).
- Executes the standard `handle()` method, allowing **method-level dependency injection**.
- Automatically deletes the message from SQS on successful execution.

Example (`app/Jobs/ConsolidateJob.php`):

```php
<?php

namespace App\Jobs;

use Gabebritto\LaravelSimpleSqs\Traits\SqsJsonJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Queueable;
use Illuminate\Queue\SerializesModels;

class ConsolidateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, SqsJsonJob;

    // These properties are automatically hydrated from the SQS JSON payload keys
    public ?string $event = null;
    public ?int $orderId = null;
    public ?float $total = null;

    /**
     * Execute the job.
     * Supports Dependency Injection through Laravel's Service Container.
     */
    public function handle(PaymentService $paymentService): void
    {
        // 1. Direct access to the auto-hydrated properties
        $orderId = $this->orderId; 
        $total = $this->total;

        // 2. Perform actions based on the event name (which is now just a normal payload key)
        if ($this->event === 'OrderCreated') {
            $paymentService->process($orderId, $total);
        }

        // NOTE: The SqsJsonJob trait automatically deletes the job from the SQS queue
        // once this method successfully completes.
    }
}
```

---

## Why does it work with Laravel's native Worker?

When the native worker runs (`php artisan queue:work`), it pulls the SQS message, decodes the raw JSON, and attempts to resolve the value inside the `"job"` key from Laravel's Service Container.

In the `register` method of the `SqsMessengerServiceProvider`, we bind each configured alias to its class:

```php
$this->app->bind('external-event', \App\Jobs\ConsolidateJob::class);
```

Since the received JSON contains `"job": "external-event"`, the worker resolves and creates an instance of `\App\Jobs\ConsolidateJob`. By default, when no specific method is defined in the job payload (like `Class@method`), the queue runner falls back to calling the `fire()` method.

The `SqsJsonJob` trait defines the `fire($job, array $data)` method. This method hydrates the class properties, hooks standard traits, and invokes your standard `handle()` method, providing a robust integration pattern.
