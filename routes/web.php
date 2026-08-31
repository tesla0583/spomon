<?php

use App\Livewire\ClientCardPage;
use App\Livewire\ClientRegistry;
use App\Livewire\SpoStatisticsPage;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::redirect('/', '/clients');

Route::get('/clients', ClientRegistry::class)->name('clients.index');
Route::get('/clients/{client}', ClientCardPage::class)->name('clients.show');
Route::get('/stats', SpoStatisticsPage::class)->name('stats.index');
