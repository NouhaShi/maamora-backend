<?php


use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\ParticipantController;
use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;
// Dashboard
Route::get('/dashboard', [DashboardController::class, 'index']);
Route::get(
    '/dashboard/progress-history',
    [DashboardController::class, 'progressHistory']
);
// Groups
Route::get('/groups', [GroupController::class, 'index']);
Route::post('/groups', [GroupController::class, 'store']);
Route::get('/groups/{id}', [GroupController::class, 'show']);
// Update and Delete Groups
Route::put('/groups/{id}', [GroupController::class, 'update']);
Route::delete('/groups/{id}', [GroupController::class, 'destroy']);
// Participants
Route::get('/groups/{groupId}/participants',
    [ParticipantController::class, 'index']);

Route::post('/groups/{groupId}/participants',
    [ParticipantController::class, 'store']);
Route::get('/participants', [ParticipantController::class, 'all']);
// Notifications
Route::get('/notifications',
    [NotificationController::class, 'index']);
    Route::get('/dashboard/notifications',
    [NotificationController::class, 'latest']
);

