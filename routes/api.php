<?php

use App\Http\Controllers\Api\V1\SubmissionActionController;
use App\Http\Controllers\Api\V1\SubmissionController;
use App\Http\Controllers\Api\V1\SubmissionSubmitController;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Resources\Api\V1\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->middleware(['auth:sanctum', EnsureUserIsActive::class, 'throttle:api'])->group(function (): void {
    Route::get('user', function (Request $request): UserResource {
        abort_unless($request->user()->tokenCan('submissions:read'), 403);

        return new UserResource($request->user());
    })->name('user');

    Route::apiResource('submissions', SubmissionController::class)->only(['index', 'store', 'show']);
    Route::post('submissions/{submission}/submit', SubmissionSubmitController::class)->name('submissions.submit');
    Route::post('submissions/{submission}/actions', SubmissionActionController::class)->name('submissions.actions.store');
});
