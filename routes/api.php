<?php

use App\Http\Controllers\ChatController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| These routes are stateless and do not require CSRF protection.
| They are prefixed with /api automatically.
|
*/

// Chat API endpoints - protected by REST API key authentication
Route::middleware(['rest.api.key'])->group(function (): void {
    // Session management
    Route::get('/sessions', [ChatController::class, 'sessions'])->name('api.sessions');
    Route::post('/sessions', [ChatController::class, 'createSession'])->name('api.sessions.create');
    Route::delete('/sessions', [ChatController::class, 'deleteSession'])->name('api.sessions.delete');
    Route::post('/sessions/rename', [ChatController::class, 'renameSession'])->name('api.sessions.rename');

    // History
    Route::get('/history', [ChatController::class, 'history'])->name('api.history');

    // Feedback
    Route::post('/feedback/message', [ChatController::class, 'feedbackMessage'])->name('api.feedback.message');
    Route::post('/feedback/conversation', [ChatController::class, 'feedbackConversation'])->name('api.feedback.conversation');

    // Send message
    Route::post('/send', [ChatController::class, 'send'])->name('api.send');

    // Stream (SSE)
    Route::get('/stream', [ChatController::class, 'stream'])->name('api.stream');

    // Ping
    Route::get('/ping', [ChatController::class, 'ping'])->name('api.ping');
});
