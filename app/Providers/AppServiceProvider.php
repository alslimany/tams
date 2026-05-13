<?php

namespace App\Providers;

use App\Services\Finance\AbiviaLedgerDriver;
use App\Services\Finance\LedgerDriver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\URL;
use Stancl\Tenancy\Events\TenancyInitialized;
use Illuminate\Support\Facades\Event;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LedgerDriver::class, AbiviaLedgerDriver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

         // Whenever a tenant is initialized, tell Laravel to use its ID as the default route parameter
        Event::listen(TenancyInitialized::class, function (TenancyInitialized $event) {
            URL::defaults([
                'tenant' => $event->tenancy->tenant->id,
            ]);
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
