<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    /**
     * The signed-in account, for the browser.
     */
    public function __invoke(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
