<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\Country;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CountryController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Country::query();

        if ($request->filled('search')) {
            $term = '%'.$request->search.'%';
            $query->where(function ($q) use ($term) {
                $q->where('name_en', 'like', $term)
                    ->orWhere('name_ar', 'like', $term)
                    ->orWhere('name_fr', 'like', $term)
                    ->orWhere('alpha2', 'like', $term)
                    ->orWhere('alpha3', 'like', $term);
            });
        }

        if ($request->filled('esim_featured')) {
            $query->where('esim_featured', $request->esim_featured === 'yes');
        }

        $countries = $query->orderBy('name_en')
            ->paginate(50)
            ->through(fn (Country $country): array => [
                'id' => $country->id,
                'alpha2' => $country->alpha2,
                'alpha3' => $country->alpha3,
                'name_en' => $country->name_en,
                'name_ar' => $country->name_ar,
                'name_fr' => $country->name_fr,
                'esim_featured' => $country->esim_featured,
            ]);

        return Inertia::render('Landlord/Countries/Index', [
            'countries' => $countries,
            'filters' => $request->only(['search', 'esim_featured']),
        ]);
    }

    public function toggleEsimFeatured(Country $country): RedirectResponse
    {
        $country->update(['esim_featured' => ! $country->esim_featured]);

        return back();
    }
}
