<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Symfony\Component\HttpFoundation\RedirectResponse;

abstract class Controller
{
    /**
     * Flash an Inertia toast and return to the previous page.
     *
     * @param  array{type: string, message: string}  $toast
     */
    protected function toast(array $toast): RedirectResponse
    {
        Inertia::flash('toast', $toast);

        return back();
    }

    /**
     * Flash an Inertia toast and redirect to a named route.
     *
     * @param  array{type: string, message: string}  $toast
     */
    protected function toastRoute(string $route, array $toast): RedirectResponse
    {
        Inertia::flash('toast', $toast);

        return redirect()->route($route);
    }
}
