<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('organizations/create', 'pages::organizations.create')
        ->name('organizations.create');
});

Route::middleware(['auth', 'verified'])
    ->prefix('o/{organization:slug}')
    ->name('organizations.')
    ->group(function () {
        Route::livewire('/', 'pages::organizations.dashboard')->name('dashboard');

        Route::livewire('members', 'pages::organizations.members')->name('members');

        Route::livewire('settings', 'pages::organizations.settings')->name('settings');
    });
