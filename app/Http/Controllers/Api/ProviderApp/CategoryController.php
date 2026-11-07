<?php

namespace App\Http\Controllers\Api\ProviderApp;

use App\Models\Category;
use Illuminate\Support\Facades\Storage;

/** GET /provider/categories: what a provider can choose to work in. Open to a provider who is not approved yet (they choose while applying). */
class CategoryController extends ProviderAppController
{
    public function index()
    {
        $categories = Category::query()->where('status', 'active')->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'image']);

        return $this->ok($categories->map(fn (Category $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'icon' => $c->image ? Storage::disk('public')->url($c->image) : null,
        ])->all());
    }
}
