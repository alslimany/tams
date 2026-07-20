<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Session;

class LanguageController extends Controller
{
    /**
     * Switch the application language.
     */
    public function switch(Request $request)
    {
        $locale = $request->query('locale');

        // Validate the locale parameter
        if (! in_array($locale, ['en', 'ar', 'fr'])) {
            return redirect('/')->with('error', 'Invalid language selection');
        }

        // Set the locale in the session
        session()->put('locale', $locale);

        // Set the application locale
        App::setLocale($locale);

        // Redirect back with success message
        return redirect()->back()->with('success', __('common.language_switched'));
    }
}
