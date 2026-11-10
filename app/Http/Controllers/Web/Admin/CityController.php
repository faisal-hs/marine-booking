<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Admin\City\SaveCityRequest;
use App\Models\City;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The destinations activities can be in, shown on the customer app's home screen and filters. A city that activities are in is switched off, never
 * deleted (the activities would lose their place).
 */
class CityController extends Controller
{
    use AuthorizesRequests;

    public function index(): View
    {
        $this->authorize('manage', City::class);

        return view('admin.cities.index', ['cities' => City::query()->withCount('activities')->orderBy('sort_order')->orderBy('id')->get()]);
    }

    public function create(): View
    {
        $this->authorize('manage', City::class);

        return view('admin.cities.form', ['city' => null]);
    }

    public function store(SaveCityRequest $request): RedirectResponse
    {
        $this->authorize('manage', City::class);

        City::create($this->values($request));

        return redirect()->route('admin.cities.index')->with('status', __('admin.cities.created'));
    }

    public function edit(City $city): View
    {
        $this->authorize('manage', City::class);

        return view('admin.cities.form', ['city' => $city]);
    }

    public function update(SaveCityRequest $request, City $city): RedirectResponse
    {
        $this->authorize('manage', City::class);

        $values = $this->values($request, $city);

        if (isset($values['image']) && $city->image) {
            Storage::disk('public')->delete($city->image);
        }

        $city->update($values);

        return redirect()->route('admin.cities.index')->with('status', __('admin.cities.updated'));
    }

    public function destroy(City $city): RedirectResponse
    {
        $this->authorize('manage', City::class);

        if ($city->activities()->exists()) {
            return back()->withErrors(['city' => __('admin.cities.in_use')]);
        }

        $city->image && Storage::disk('public')->delete($city->image);
        $city->delete();

        return redirect()->route('admin.cities.index')->with('status', __('admin.cities.deleted'));
    }

    /** @return array<string, mixed> */
    private function values(SaveCityRequest $request, ?City $city = null): array
    {
        $values = ['name_en' => $request->validated('name_en'), 'name_ar' => $request->validated('name_ar'), 'is_active' => $request->boolean('is_active'),
            'sort_order' => (int) $request->validated('sort_order'), 'slug' => $request->validated('slug') ?: ($city?->slug ?? $this->freeSlug($request->validated('name_en')))];

        if ($file = $request->file('image')) {
            $values['image'] = $file->storeAs('cities', Str::uuid().'.'.$file->extension(), 'public');
        }

        return $values;
    }

    private function freeSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'city';
        $slug = $base;

        for ($i = 2; City::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
