<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/admin'));

// Buyer check only: signs in the seeded user so the headless browser can reach the panel.
if (app()->environment('local')) {
    Route::get('/demo-login', function () {
        auth()->login(\App\Models\User::query()->firstOrFail());

        return redirect(request('to', '/admin/products'));
    });
}
