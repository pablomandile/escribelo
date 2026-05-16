<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\WorkerController as AdminWorkerController;
use App\Http\Controllers\Admin\CloudflareController as AdminCloudflareController;
use App\Http\Controllers\Admin\QueueController as AdminQueueController;
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

// Página accesible para usuarios pendientes — no requiere `approved`.
Route::middleware('auth')->group(function () {
    Route::get('/account/pending', [AccountController::class, 'pending'])->name('account.pending');
});

Route::get('/escribelo', [TranscriptionFileController::class, 'index'])
    ->middleware(['auth', 'verified', 'approved'])
    ->name('dashboard');

Route::middleware(['auth', 'approved'])->group(function () {
    Route::post('/transcriptions', [TranscriptionFileController::class, 'store'])->name('transcriptions.store');
    Route::post('/transcriptions/from-paths', [TranscriptionFileController::class, 'storeFromPaths'])->name('transcriptions.fromPaths');
    Route::get('/transcriptions/unfiled', [TranscriptionFileController::class, 'listUnfiled'])->name('transcriptions.unfiled');
    Route::post('/transcriptions/move-bulk', [TranscriptionFileController::class, 'moveBulkToFolder'])->name('transcriptions.moveBulk');
    Route::get('/transcriptions/{transcriptionFile}', [TranscriptionFileController::class, 'show'])->whereNumber('transcriptionFile')->name('transcriptions.show');
    Route::get('/transcriptions/{transcriptionFile}/audio', [TranscriptionFileController::class, 'streamAudio'])->whereNumber('transcriptionFile')->name('transcriptions.audio');
    Route::get('/transcriptions/{transcriptionFile}/artwork', [TranscriptionFileController::class, 'streamArtwork'])->whereNumber('transcriptionFile')->name('transcriptions.artwork');
    Route::get('/transcriptions/{transcriptionFile}/download/{format}', [TranscriptionFileController::class, 'download'])->whereNumber('transcriptionFile')->whereIn('format', ['txt', 'srt', 'pdf'])->name('transcriptions.download');
    Route::get('/transcriptions/{transcriptionFile}/audio/cleaned', [TranscriptionFileController::class, 'streamCleanedAudio'])->whereNumber('transcriptionFile')->name('transcriptions.audio.cleaned');
    Route::post('/transcriptions/{transcriptionFile}/cleaned/replace', [TranscriptionFileController::class, 'replaceWithCleaned'])->whereNumber('transcriptionFile')->name('transcriptions.cleaned.replace');
    Route::post('/transcriptions/{transcriptionFile}/cleaned/save-as-new', [TranscriptionFileController::class, 'saveCleanedAsNew'])->whereNumber('transcriptionFile')->name('transcriptions.cleaned.saveAsNew');
    Route::delete('/transcriptions/{transcriptionFile}/cleaned', [TranscriptionFileController::class, 'discardCleaned'])->whereNumber('transcriptionFile')->name('transcriptions.cleaned.discard');
    Route::patch('/transcriptions/{transcriptionFile}/folder', [TranscriptionFileController::class, 'updateFolder'])->whereNumber('transcriptionFile')->name('transcriptions.folder');
    Route::patch('/transcriptions/{transcriptionFile}/rename', [TranscriptionFileController::class, 'rename'])->whereNumber('transcriptionFile')->name('transcriptions.rename');
    Route::post('/transcriptions/{transcriptionFile}/summary', [TranscriptionFileController::class, 'summarize'])->whereNumber('transcriptionFile')->name('transcriptions.summary');
    Route::delete('/transcriptions/{transcriptionFile}/summary', [TranscriptionFileController::class, 'cancelSummary'])->whereNumber('transcriptionFile')->name('transcriptions.summary.cancel');
    Route::patch('/transcriptions/{transcriptionFile}/text', [TranscriptionFileController::class, 'updateText'])->whereNumber('transcriptionFile')->name('transcriptions.text.update');
    Route::delete('/transcriptions/{transcriptionFile}/text', [TranscriptionFileController::class, 'restoreText'])->whereNumber('transcriptionFile')->name('transcriptions.text.restore');
    Route::delete('/transcriptions/{transcriptionFile}', [TranscriptionFileController::class, 'destroy'])->whereNumber('transcriptionFile')->name('transcriptions.destroy');

    Route::get('/library/browse', [AudioLibraryController::class, 'browse'])->name('library.browse');

    Route::get('/folders', [TranscriptionFolderController::class, 'index'])->name('folders.index');
    Route::get('/folders/{folder}', [TranscriptionFolderController::class, 'show'])->name('folders.show');
    Route::post('/folders', [TranscriptionFolderController::class, 'store'])->name('folders.store');
    Route::delete('/folders/{folder}', [TranscriptionFolderController::class, 'destroy'])->name('folders.destroy');

    Route::get('/modelo', fn () => \Inertia\Inertia::render('About/Model'))->name('about.model');
    Route::get('/faq', fn () => \Inertia\Inertia::render('About/Faq'))->name('about.faq');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/settings', [ProfileController::class, 'updateSettings'])->name('profile.settings');
    Route::patch('/profile/theme', [ProfileController::class, 'updateTheme'])->name('profile.theme');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Panel de administración — solo admins.
    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/settings', [AdminSettingsController::class, 'edit'])->name('settings.edit');
        Route::patch('/settings/mode', [AdminSettingsController::class, 'updateMode'])->name('settings.mode');
        Route::patch('/settings/whisper-timeout', [AdminSettingsController::class, 'updateWhisperTimeout'])->name('settings.whisperTimeout');
        Route::post('/settings/refresh-gpu', [AdminSettingsController::class, 'refreshGpu'])->name('settings.refreshGpu');

        Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
        Route::post('/users/{user}/approve', [AdminUserController::class, 'approve'])->whereNumber('user')->name('users.approve');
        Route::post('/users/{user}/revoke', [AdminUserController::class, 'revoke'])->whereNumber('user')->name('users.revoke');
        Route::patch('/users/{user}/limit', [AdminUserController::class, 'updateLimit'])->whereNumber('user')->name('users.limit');
        Route::patch('/users/{user}/role', [AdminUserController::class, 'updateRole'])->whereNumber('user')->name('users.role');
        Route::delete('/users/{user}', [AdminUserController::class, 'destroy'])->whereNumber('user')->name('users.destroy');

        Route::get('/worker/status', [AdminWorkerController::class, 'status'])->name('worker.status');
        Route::post('/worker/start', [AdminWorkerController::class, 'start'])->name('worker.start');
        Route::post('/worker/stop', [AdminWorkerController::class, 'stop'])->name('worker.stop');
        Route::post('/worker/restart', [AdminWorkerController::class, 'restart'])->name('worker.restart');

        Route::get('/cloudflared/status', [AdminCloudflareController::class, 'status'])->name('cloudflared.status');
        Route::post('/cloudflared/start', [AdminCloudflareController::class, 'start'])->name('cloudflared.start');
        Route::post('/cloudflared/stop', [AdminCloudflareController::class, 'stop'])->name('cloudflared.stop');
        Route::post('/cloudflared/restart', [AdminCloudflareController::class, 'restart'])->name('cloudflared.restart');

        Route::get('/queue/status', [AdminQueueController::class, 'status'])->name('queue.status');
        Route::post('/queue/start', [AdminQueueController::class, 'start'])->name('queue.start');
        Route::post('/queue/stop', [AdminQueueController::class, 'stop'])->name('queue.stop');
        Route::post('/queue/restart', [AdminQueueController::class, 'restart'])->name('queue.restart');
    });
});

require __DIR__.'/auth.php';
