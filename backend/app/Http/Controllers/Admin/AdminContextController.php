<?php

namespace App\Http\Controllers\Admin;

use App\Admin\AdminCycles;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * The cycles the administration header can select, with their domains and
 * subcategories, and the default selection.
 */
class AdminContextController extends Controller
{
    public function __invoke(AdminCycles $cycles): JsonResponse
    {
        return response()->json(['data' => $cycles->context()]);
    }
}
