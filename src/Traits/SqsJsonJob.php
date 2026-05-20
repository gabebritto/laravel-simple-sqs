<?php

namespace Gabebritto\LaravelSimpleSqs\Traits;

use Gabebritto\LaravelSimpleSqs\Facades\SqsPublisher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

trait SqsJsonJob
{
    /**
     * The raw data block from SQS.
     */
    protected array $sqsData = [];

    /**
     * The raw SQS job instance.
     */
    protected ?Job $sqsJob = null;

    /**
     * The event name (if present as a payload parameter).
     */
    public ?string $event = null;

    /**
     * The full payload data.
     */
    public array $payload = [];

    /**
     * Dispatch the job to SQS as a pure JSON payload statically.
     *
     * @param  array  $payload  The payload details (e.g. ['order_id' => 123])
     * @param  string|null  $queueUrl  Optional SQS queue URL override
     * @return \Aws\Result
     *
     * @throws \RuntimeException
     */
    public static function dispatchSqsJson(array $payload, ?string $queueUrl = null)
    {
        $jobClass = static::class;
        $handlers = config('sqs-messenger.handlers', []);
        
        // Find the SQS job alias mapped to this class in configuration
        $alias = array_search($jobClass, $handlers, true);

        if (!$alias) {
            throw new RuntimeException("Job class [{$jobClass}] is not mapped to any alias in config/sqs-messenger.php");
        }

        return SqsPublisher::dispatch($alias, $payload, $queueUrl);
    }

    /**
     * Publish the current job instance to SQS based on its properties.
     * Useful when instantiating the class first and then pushing it.
     *
     * @param  string|null  $queueUrl
     * @return \Aws\Result
     *
     * @throws \RuntimeException
     */
    public function publish(?string $queueUrl = null)
    {
        $jobClass = static::class;
        $handlers = config('sqs-messenger.handlers', []);
        $alias = array_search($jobClass, $handlers, true);

        if (!$alias) {
            throw new RuntimeException("Job class [{$jobClass}] is not mapped to any alias in config/sqs-messenger.php");
        }

        // Get the payload dynamically from properties or the default payload array
        $payload = $this->getSerializablePayload();

        return SqsPublisher::dispatch($alias, $payload, $queueUrl);
    }

    /**
     * Execute the job received from the SQS queue.
     * This is invoked automatically by Laravel's Queue Worker.
     *
     * @param  \Illuminate\Contracts\Queue\Job  $job  The raw SQS job instance from Laravel.
     * @param  array  $data  The "data" block parsed from the SQS JSON body.
     * @return void
     */
    public function fire(Job $job, array $data): void
    {
        $this->sqsJob = $job;
        $this->sqsData = $data;

        // Set base attributes
        $this->payload = $data;
        $this->event = $data['event'] ?? null;

        // Link SQS job instance to Laravel's standard InteractsWithQueue trait if present
        if (method_exists($this, 'setJob')) {
            $this->setJob($job);
        }

        // Hydrate class properties using the payload data
        $this->hydrateProperties($this->payload);

        // Execute the handler method using Laravel's container to support dependency injection
        if (method_exists($this, 'handle')) {
            app()->call([$this, 'handle']);
        }

        // Automatically delete the job from the SQS queue upon success
        if ($job && !$job->isDeleted() && !$job->isReleased()) {
            $job->delete();
        }
    }

    /**
     * Dynamically map payload fields to matching class properties.
     * Supports exact name matching, camelCase, and snake_case properties.
     *
     * @param  array  $payload
     * @return void
     */
    protected function hydrateProperties(array $payload): void
    {
        foreach ($payload as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
                continue;
            }

            // Match snake_case payload key to camelCase class property
            $camelKey = Str::camel($key);
            if (property_exists($this, $camelKey)) {
                $this->{$camelKey} = $value;
                continue;
            }

            // Match camelCase payload key to snake_case class property
            $snakeKey = Str::snake($key);
            if (property_exists($this, $snakeKey)) {
                $this->{$snakeKey} = $value;
            }
        }
    }

    /**
     * Retrieve serializable properties for SQS dispatching.
     * Excludes native Laravel Queueable properties.
     *
     * @return array
     */
    protected function getSerializablePayload(): array
    {
        if (!empty($this->payload)) {
            return $this->payload;
        }

        $reflection = new ReflectionClass($this);
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);
        
        // standard Laravel job properties to ignore
        $laravelProperties = [
            'connection', 'queue', 'chainConnection', 'chainQueue', 'chainCatchCallbacks',
            'delay', 'afterCommit', 'middleware', 'tries', 'maxExceptions', 'backoff', 'timeout',
            'payload', 'sqsJob', 'sqsData'
        ];

        $serialized = [];
        foreach ($properties as $property) {
            $name = $property->getName();
            if (in_array($name, $laravelProperties, true)) {
                continue;
            }
            if ($property->isInitialized($this)) {
                $serialized[$name] = $property->getValue($this);
            }
        }

        return $serialized;
    }
}
