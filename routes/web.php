<?php

use App\Http\Controllers\AudioLibraryController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TranscriptionFileController;
use App\Http\Controllers\TranscriptionFolderController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', [TranscriptionFileController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::post('/transcriptions', [TranscriptionFileController::class, 'store'])->name('transcriptions.store');
    Route::post('/transcriptions/from-paths', [TranscriptionFileController::class, 'storeFromPaths'])->name('transcriptions.fromPaths');
    Route::get('/transcriptions/{transcriptionFile}', [TranscriptionFileController::class, 'show'])->name('transcriptions.show');
    Route::get('/transcriptions/{transcriptionFile}/audio', [TranscriptionFileController::class, 'streamAudio'])->name('transcriptions.audio');
    Route::patch('/transcriptions/{transcriptionFile}/folder', [TranscriptionFileController::class, 'updateFolder'])->name('transcriptions.folder');
    Route::delete('/transcriptions/{transcriptionFile}', [TranscriptionFileController::class, 'destroy'])->name('transcriptions.destroy');

    Route::get('/library/browse', [AudioLibraryController::class, 'browse'])->name('library.browse');

    Route::get('/folders', [TranscriptionFolderController::class, 'index'])->name('folders.index');
    Route::get('/folders/{folder}', [TranscriptionFolderController::class, 'show'])->name('folders.show');
    Route::post('/folders', [TranscriptionFolderController::class, 'store'])->name('folders.store');

    Route::get('/modelo', fn () => \Inertia\Inertia::render('About/Model'))->name('about.model');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
