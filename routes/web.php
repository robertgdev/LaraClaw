<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// Login page (root)
Route::inertia('/', 'Login')->name('login');

// Chat routes
Route::inertia('/chat', 'Chat')->name('chat.index');
Route::get('/chat/{sessionKey}', function (string $sessionKey) {
    return Inertia::render('Chat', ['sessionKey' => $sessionKey]);
})->name('chat.show');

// Logout route
Route::get('/logout', function () {
    // Clear the token from localStorage is handled client-side
    // This route just redirects to login
    return redirect()->route('login');
})->name('logout');
