<?php

namespace Gabebritto\LaravelSimpleSqs;

use Aws\Result;
use Aws\Sqs\SqsClient;
use Illuminate\Queue\SqsQueue;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

class SqsPublisher
{
    /**
     * The SQS client instance.
     */
    protected ?SqsClient $sqsClient;

    /**
     * The Laravel queue connection name to extract credentials/configurations from.
     */
    protected string $connectionName;

    /**
     * Create a new SqsPublisher instance.
     *
     * @param  \Aws\Sqs\SqsClient|null  $sqsClient
     * @param  string  $connectionName
     */
    public function __construct(?SqsClient $sqsClient = null, string $connectionName = 'sqs')
    {
        $this->sqsClient = $sqsClient;
        $this->connectionName = $connectionName;
    }

    /**
     * Dispatch a message to AWS SQS with the simplified JSON payload format.
     *
     * @param  string  $jobAlias  The queue alias (stored under 'job' in the JSON)
     * @param  array  $payload  The payload details (stored under 'data' in the JSON)
     * @param  string|null  $queueUrl  Optional SQS queue URL override. Fallbacks to configuration if not provided.
     * @return \Aws\Result
     *
     * @throws \RuntimeException
     * @throws \JsonException
     */
    public function dispatch(string $jobAlias, array $payload, ?string $queueUrl = null): Result
    {
        $client = $this->getSqsClient();
        $targetQueueUrl = $this->resolveQueueUrl($queueUrl);

        // Build the simplified JSON structure where payload is the data block directly
        $messageBody = json_encode([
            'job' => $jobAlias,
            'data' => $payload,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $client->sendMessage([
            'QueueUrl' => $targetQueueUrl,
            'MessageBody' => $messageBody,
        ]);
    }

    /**
     * Resolve the AWS SQS Client.
     * Tries to extract it from Laravel's active queue connection first, with fallback to manual instantiation.
     *
     * @return \Aws\Sqs\SqsClient
     * @throws \RuntimeException
     */
    public function getSqsClient(): SqsClient
    {
        if ($this->sqsClient) {
            return $this->sqsClient;
        }

        // Try resolving the pre-configured client from Laravel's active SQS queue driver
        try {
            $connection = Queue::connection($this->connectionName);
            if ($connection instanceof SqsQueue) {
                return $this->sqsClient = $connection->getSqs();
            }
        } catch (\Throwable $e) {
            // Log/ignore and fallback to manual creation if Queue Manager isn't booted (e.g. testing)
        }

        // Fallback: manually construct SqsClient from Laravel's connection settings
        $config = config("queue.connections.{$this->connectionName}");
        if (!$config) {
            throw new RuntimeException("Queue connection configuration [{$this->connectionName}] is not defined.");
        }

        $options = [
            'region' => $config['region'] ?? 'us-east-1',
            'version' => 'latest',
        ];

        // Only pass credentials block if key and secret are specified
        if (!empty($config['key']) && !empty($config['secret'])) {
            $options['credentials'] = [
                'key' => $config['key'],
                'secret' => $config['secret'],
                'token' => $config['token'] ?? null,
            ];
        }

        if (!empty($config['endpoint'])) {
            $options['endpoint'] = $config['endpoint'];
        }

        return $this->sqsClient = new SqsClient($options);
    }

    /**
     * Resolve the target SQS queue URL.
     *
     * @param  string|null  $queueUrl
     * @return string
     * @throws \RuntimeException
     */
    protected function resolveQueueUrl(?string $queueUrl = null): string
    {
        if ($queueUrl) {
            return $queueUrl;
        }

        // 1. Check for custom queue_url in the package configuration
        $packageQueue = config('sqs-messenger.queue_url');
        if ($packageQueue) {
            return $packageQueue;
        }

        // 2. Fetch standard credentials from Laravel's SQS connection config
        $config = config("queue.connections.{$this->connectionName}");
        if (!$config) {
            throw new RuntimeException("Queue connection configuration [{$this->connectionName}] is not defined.");
        }

        $prefix = $config['prefix'] ?? '';
        $queue = $config['queue'] ?? '';

        // If the 'queue' configuration itself is a full URL, return it directly
        if (filter_var($queue, FILTER_VALIDATE_URL)) {
            return $queue;
        }

        // If 'prefix' is a URL, construct the full URL
        if (filter_var($prefix, FILTER_VALIDATE_URL)) {
            return rtrim($prefix, '/') . '/' . $queue;
        }

        throw new RuntimeException('Unable to resolve SQS Queue URL from Laravel configuration.');
    }
}
