<?php

namespace IICN\Subscription;

use IICN\Subscription\Commands\CheckSubscriptionRenewals;
use IICN\Subscription\Commands\RetryCheckPendingTransaction;
use IICN\Subscription\Http\Middleware\AuthSubscription;
use IICN\Subscription\Http\Middleware\ValidateSubscription;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class SubscriptionServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'subscription');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');

        $this->runningInConsole();

        $this->publish();

        $this->app->bind('subscription', function () {
            $loggedInUser = Auth::guard(config('subscription.guard'))->user();
            if ($loggedInUser instanceof HasSubscription) {
                return new \IICN\Subscription\Services\Subscription($loggedInUser);
            }
        });
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/subscription.php', 'subscription'
        );

        app('router')->aliasMiddleware('validate.subscription', ValidateSubscription::class);
        app('router')->aliasMiddleware('auth.subscription', AuthSubscription::class);

        $this->app->bind('subscriptionResponse', function () {
            $responseClass = config('subscription.response_class');
            if (app($responseClass) instanceof SubscriptionResponse) {
                return new $responseClass();
            }
        });
    }

    /**
     * publishes the service provider.
     */
    public function publish(): void
    {
        $this->publishes([
            __DIR__.'/../config/subscription.php' => config_path('subscription.php'),
        ]);

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/subscription'),
        ]);

        $this->publishes([
            __DIR__.'/../database/migrations/' => database_path('migrations'),
        ], 'subscription-migrations');
    }

    /**
     * runningInConsole the service provider.
     */
    public function runningInConsole(): void
    {
        if ($this->app->runningInConsole()) {
             $this->commands([
                 RetryCheckPendingTransaction::class,
                 CheckSubscriptionRenewals::class,
             ]);
        }
    }
}
