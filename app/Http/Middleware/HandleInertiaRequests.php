<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'app' => [
                'name' => __('common.book_now'), // Translates based on active locale
            ],
            'csrf_token' => csrf_token(),
            'auth' => [
                // Deferred: tenancy is initialized by route middleware that runs
                // after the web stack (where this middleware lives). Eager evaluation
                // would always see tenant() as null and hide admin nav links.
                'user' => fn () => (function_exists('tenant') && tenant())
                    ? $request->user()
                    : null,
                'landlordUser' => fn () => auth('landlord')->user(),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'insurance_quote' => fn () => $request->session()->get('insurance_quote'),
                'issue_command_preview' => fn () => $request->session()->get('issue_command_preview'),
                'newToken' => fn () => $request->session()->get('newToken'),
            ],
            'tenant' => fn () => [
                'id' => function_exists('tenant') && tenant() ? tenant()->id : null,
                'companyName' => function_exists('tenant') && tenant() ? tenant()->company_name : null,
                'status' => function_exists('tenant') && tenant() ? tenant()->status : null,
            ],
            'agencySettings' => fn () => $this->resolveAgencySettings(),
            'ziggy' => fn () => [
                ...(new Ziggy)->toArray(),
                'location' => $request->url(),
                'defaults' => [
                    'tenant' => function_exists('tenant') && tenant() ? tenant()->id : $request->route('tenant'),
                ],
            ],
            'locale' => session('locale', app()->getLocale()),
            'translations' => function () {
                $locale = session('locale', app()->getLocale());
                $sourceLangPath = base_path('lang');
                $phpTranslations = [];
                if (file_exists($sourceLangPath.'/'.$locale.'.json')) {
                    $phpTranslations = json_decode(file_get_contents($sourceLangPath.'/'.$locale.'.json'), true);
                }

                $commonTranslations = [];
                if (file_exists($sourceLangPath.'/'.$locale.'/common.php')) {
                    $commonTranslations = include $sourceLangPath.'/'.$locale.'/common.php';
                }

                return array_merge($phpTranslations, ['common' => $commonTranslations]);
            },
        ];
    }

    /**
     * @return array{can_use_own_airline_credentials: bool, force_use_default_agency: bool, can_manage_providers: bool}|null
     */
    private function resolveAgencySettings(): ?array
    {
        if (! function_exists('tenant') || ! tenant()) {
            return null;
        }

        try {
            $settings = \App\Models\Tenant\AgencySetting::current();

            return [
                'can_use_own_airline_credentials' => $settings->canUseOwnAirlineCredentials(),
                'force_use_default_agency' => $settings->isForcedToUseDefaultAgency(),
                'can_manage_providers' => ! $settings->isForcedToUseDefaultAgency()
                    && $settings->canUseOwnAirlineCredentials(),
            ];
        } catch (\Throwable $e) {
            report($e);

            return [
                'can_use_own_airline_credentials' => true,
                'force_use_default_agency' => false,
                'can_manage_providers' => true,
            ];
        }
    }
}
