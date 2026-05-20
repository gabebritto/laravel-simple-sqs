<?php

namespace Gabebritto\LaravelSimpleSqs;

use Illuminate\Support\ServiceProvider;

class SqsMessengerServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        // Merge package configuration so defaults are accessible
        $this->mergeConfigFrom(
            __DIR__ . '/../config/sqs-messenger.php', 'sqs-messenger'
        );

        // Bind the Publisher in the container as a singleton
        $this->app->singleton('sqs-publisher', function (): SqsPublisher {
            return new SqsPublisher();
        });

        // Register SQS message aliases to their corresponding job classes in the container
        $this->registerHandlers();
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        // Allow users to publish the configuration file to their application config directory
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/sqs-messenger.php' => config_path('sqs-messenger.php'),
            ], 'sqs-messenger-config');
        }
    }

    /**
     * Read handlers from configuration and bind each alias to its handler class.
     * This is key to enabling Laravel's queue worker to resolve jobs using the SQS job alias.
     *
     * @return void
     */
    protected function registerHandlers(): void
    {
        $handlers = config('sqs-messenger.handlers', []);

        if (is_array($handlers)) {
            foreach ($handlers as $alias => $handlerClass) {
                // Binding the alias to the target class allows Container::make($alias) to resolve the class instance
                $this->app->bind($alias, $handlerClass);
            }
        }
    }
}
