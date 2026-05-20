<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AWS SQS Queue URL
    |--------------------------------------------------------------------------
    |
    | Define the SQS queue URL where the publisher will dispatch messages by default.
    | If left null, it will fallback to the standard Laravel queue configuration:
    | config('queue.connections.sqs.prefix') / config('queue.connections.sqs.queue')
    |
    */
    'queue_url' => env('SQS_MESSENGER_QUEUE_URL'),

    /*
    |--------------------------------------------------------------------------
    | SQS Queue Job Handlers Mapping
    |--------------------------------------------------------------------------
    |
    | Here you map the SQS message 'job' aliases to their corresponding handler
    | classes. When a message is consumed, the Service Provider binds these
    | aliases to the container, allowing Laravel's native queue worker to resolve
    | the handler and execute its 'fire' method.
    |
    */
    'handlers' => [
        'external-event' => \App\Jobs\ConsolidateJob::class,
    ],
];
