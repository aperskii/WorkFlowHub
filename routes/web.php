<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

// Public so an invited guest can see the invitation before signing in. Accepting
// it is gated behind auth and verification inside the component.
Route::livewire('invitations/{token}', 'pages::invitations.show')->name('invitations.show');

require __DIR__.'/organizations.php';
require __DIR__.'/settings.php';
