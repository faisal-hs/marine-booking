<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\City;


/**
 * The destinations activities can be in, shown on the customer app's home screen and filters. A city that activities are in is switched off, never
 * deleted (the activities would lose their place).
 */
class CityController extends Controller
{

    public function index()
    {
        return City::query()->withCount('activities')->orderBy('sort_order')->orderBy('id')->get();
    }
}
