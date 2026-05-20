<?php

namespace App\Jobs;

use Gabebritto\LaravelSimpleSqs\Traits\SqsJsonJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Queueable;
use Illuminate\Queue\SerializesModels;

/**
 * Example Consumer Job Class using standard Laravel Queue components.
 *
 * Place this class within your application's `app/Jobs/` directory.
 * Map this class to your SQS queue alias in `config/sqs-messenger.php`.
 *
 * This class implements standard Laravel queue interfaces and uses standard traits
 * alongside our package's SqsJsonJob trait, enabling seamless compatibility.
 */
class ConsolidateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, SqsJsonJob;

    /**
     * Public properties are automatically hydrated by the SqsJsonJob trait
     * when the SQS JSON payload is processed.
     *
     * JSON payload: {"order_id": 123, "total": 150.00, "event": "OrderCreated"}
     * Auto-hydrates: $orderId, $total, and $event
     */
    public ?int $orderId = null;
    public ?float $total = null;
    public ?string $event = null;

    /**
     * Execute the job.
     *
     * Laravel's container resolves this method, allowing you to type-hint
     * and inject any services or dependencies required for processing.
     *
     * @return void
     */
    public function handle(): void
    {
        // 1. You have direct access to hydrated properties
        $orderId = $this->orderId;
        $total = $this->total;
        $eventName = $this->event; // e.g. 'OrderCreated' (passed as a regular payload key)

        // 2. You also have access to the raw payload array via the trait
        $fullPayload = $this->payload; // e.g. ['order_id' => 123, 'total' => 150.00, 'event' => 'OrderCreated']

        // 3. Put your business logic here
        // Example:
        // if ($eventName === 'OrderCreated') { ... }

        // NOTE: SqsJsonJob automatically calls $job->delete() when this method completes
        // successfully, so there is no need to manually call $this->delete().
    }
}
