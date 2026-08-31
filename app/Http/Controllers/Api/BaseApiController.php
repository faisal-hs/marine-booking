<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponseTrait;

/**
 * Every versioned API controller (Admin\, Provider\, Customer\, Public\)
 * extends this instead of the base Controller directly.
 */
abstract class BaseApiController extends Controller
{
    use ApiResponseTrait;
}
