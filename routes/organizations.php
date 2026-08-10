<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('organizations/create', 'pages::organizations.create')
        ->name('organizations.create');
});

Route::middleware(['auth', 'verified'])
    ->prefix('o/{organization:slug}')
    ->name('organizations.')
    // Nested models resolve through their parent organization, so a slug that
    // belongs to another tenant is a 404 rather than an authorization failure.
    ->scopeBindings()
    ->group(function () {
        Route::livewire('/', 'pages::organizations.dashboard')->name('dashboard');

        Route::livewire('members', 'pages::organizations.members')->name('members');

        Route::livewire('settings', 'pages::organizations.settings')->name('settings');

        Route::livewire('projects', 'pages::projects.index')->name('projects.index');

        // Registered before the slug route so it is never swallowed by it.
        Route::livewire('projects/new', 'pages::projects.create')->name('projects.create');

        Route::livewire('projects/{project:slug}', 'pages::projects.show')->name('projects.show');
    });
