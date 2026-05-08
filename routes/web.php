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

Route::get('/escribelo', [TranscriptionFileController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::post('/transcriptions', [TranscriptionFileController::class, 'store'])->name('transcriptions.store');
    Route::post('/transcriptions/from-paths', [TranscriptionFileController::class, 'storeFromPaths'])->name('transcriptions.fromPaths');
    Route::get('/transcriptions/unfiled', [TranscriptionFileController::class, 'listUnfiled'])->name('transcriptions.unfiled');
    Route::post('/transcriptions/move-bulk', [TranscriptionFileController::class, 'moveBulkToFolder'])->name('transcriptions.moveBulk');
    Route::get('/transcriptions/{transcriptionFile}', [TranscriptionFileController::class, 'show'])->whereNumber('transcriptionFile')->name('transcriptions.show');
    Route::get('/transcriptions/{transcriptionFile}/audio', [TranscriptionFileController::class, 'streamAudio'])->whereNumber('transcriptionFile')->name('transcriptions.audio');
    Route::get('/transcriptions/{transcriptionFile}/audio/cleaned', [TranscriptionFileController::class, 'streamCleanedAudio'])->whereNumber('transcriptionFile')->name('transcriptions.audio.cleaned');
    Route::post('/transcriptions/{transcriptionFile}/cleaned/replace', [TranscriptionFileController::class, 'replaceWithCleaned'])->whereNumber('transcriptionFile')->name('transcriptions.cleaned.replace');
    Route::post('/transcriptions/{transcriptionFile}/cleaned/save-as-new', [TranscriptionFileController::class, 'saveCleanedAsNew'])->whereNumber('transcriptionFile')->name('transcriptions.cleaned.saveAsNew');
    Route::delete('/transcriptions/{transcriptionFile}/cleaned', [TranscriptionFileController::class, 'discardCleaned'])->whereNumber('transcriptionFile')->name('transcriptions.cleaned.discard');
    Route::patch('/transcriptions/{transcriptionFile}/folder', [TranscriptionFileController::class, 'updateFolder'])->whereNumber('transcriptionFile')->name('transcriptions.folder');
    Route::post('/transcriptions/{transcriptionFile}/summary', [TranscriptionFileController::class, 'summarize'])->whereNumber('transcriptionFile')->name('transcriptions.summary');
    Route::delete('/transcriptions/{transcriptionFile}', [TranscriptionFileController::class, 'destroy'])->whereNumber('transcriptionFile')->name('transcriptions.destroy');

    Route::get('/library/browse', [AudioLibraryController::class, 'browse'])->name('library.browse');

    Route::get('/folders', [TranscriptionFolderController::class, 'index'])->name('folders.index');
    Route::get('/folders/{folder}', [TranscriptionFolderController::class, 'show'])->name('folders.show');
    Route::post('/folders', [TranscriptionFolderController::class, 'store'])->name('folders.store');

    Route::get('/modelo', fn () => \Inertia\Inertia::render('About/Model'))->name('about.model');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/settings', [ProfileController::class, 'updateSettings'])->name('profile.settings');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
