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
        $agencySettings = null;

        if (function_exists('tenant') && tenant()) {
            try {
                $settings = \App\Models\Tenant\AgencySetting::current();
                $agencySettings = [
                    'can_use_own_airline_credentials' => $settings->canUseOwnAirlineCredentials(),
                    'force_use_default_agency' => $settings->isForcedToUseDefaultAgency(),
                    'can_manage_providers' => ! $settings->isForcedToUseDefaultAgency()
                        && $settings->canUseOwnAirlineCredentials(),
                ];
            } catch (\Throwable $e) {
                // Table doesn't exist or other error - default to allowing own credentials
                report($e);
                $agencySettings = [
                    'can_use_own_airline_credentials' => true,
                    'force_use_default_agency' => false,
                    'can_manage_providers' => true,
                ];
            }
        }

        return [
            ...parent::share($request),
            'app' => [
                'name' => __('common.book_now'), // Translates based on active locale
            ],
            'auth' => [
                'user' => $request->user(),
                'landlordUser' => auth('landlord')->user(),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'insurance_quote' => fn () => $request->session()->get('insurance_quote'),
                'issue_command_preview' => fn () => $request->session()->get('issue_command_preview'),
            ],
            'tenant' => [
                'id' => function_exists('tenant') && tenant() ? tenant()->id : null,
                'companyName' => function_exists('tenant') && tenant() ? tenant()->company_name : null,
                'status' => function_exists('tenant') && tenant() ? tenant()->status : null,
            ],
            'agencySettings' => $agencySettings,
            'ziggy' => fn () => [
                ...(new Ziggy)->toArray(),
                'location' => $request->url(),
            ],
            'locale' => session('locale', app()->getLocale()),
            'translations' => function () {
                $locale = session('locale', app()->getLocale());
                $phpTranslations = [];
                if (file_exists(lang_path($locale.'.json'))) {
                    $phpTranslations = json_decode(file_get_contents(lang_path($locale.'.json')), true);
                }

                $commonTranslations = [];
                if (file_exists(lang_path($locale.'/common.php'))) {
                    $commonTranslations = include lang_path($locale.'/common.php');
                }

                return array_merge($phpTranslations, ['common' => $commonTranslations]);
            },
        ];
    }
}
