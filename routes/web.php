<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'pages.home')->name('home');

Route::livewire('/providers', 'pages::providers')->name('providers');
Route::livewire('/providers/{slug}', 'pages::provider')->name('provider');

