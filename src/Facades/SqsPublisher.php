<?php

namespace Gabebritto\LaravelSimpleSqs\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Aws\Result dispatch(string $jobAlias, array $payload, string|null $queueUrl = null)
 * @method static \Aws\Sqs\SqsClient getSqsClient()
 *
 * @see \Gabebritto\LaravelSimpleSqs\SqsPublisher
 */
class SqsPublisher extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'sqs-publisher';
    }
}
