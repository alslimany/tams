<?php

namespace App\Providers;

use App\Channels\WhatsAppChannel;
use App\Listeners\PostLedgerEntryOnWalletTransaction;
use App\Services\Finance\AbiviaLedgerDriver;
use App\Services\Finance\LedgerDriver;
use App\Services\Notifications\AdvLyClient;
use Bavix\Wallet\Internal\Events\TransactionCreatedEventInterface;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Stancl\Tenancy\Events\TenancyInitialized;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LedgerDriver::class, AbiviaLedgerDriver::class);

        $this->app->singleton(AdvLyClient::class, fn () => new AdvLyClient(
            token: config('services.advly.token', ''),
            baseUrl: config('services.advly.base_url', 'https://adv.ly/api/v1'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();

        // Register WhatsApp notification channel
        $this->app->make(ChannelManager::class)->extend('whatsapp', fn ($app) => $app->make(WhatsAppChannel::class));

        // Whenever a tenant is initialized, tell Laravel to use its ID as the default route parameter
        Event::listen(TenancyInitialized::class, function (TenancyInitialized $event) {
            URL::defaults([
                'tenant' => $event->tenancy->tenant->id,
            ]);
        });

        Event::listen(
            TransactionCreatedEventInterface::class,
            PostLedgerEntryOnWalletTransaction::class,
        );
    }

    /**
     * Configure API rate limiters.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            $user = $request->user();

            return $user
                ? Limit::perMinute(60)->by($user->id)
                : Limit::perMinute(10)->by($request->ip());
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null
        );

    }
}
