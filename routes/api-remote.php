<?php

use Illuminate\Support\Facades\Route;
use Wan0v\Pouch\Http\Controllers\Remote\SyncController;

/*
 * Routes for the Pouch agent running on a node.
 *
 * These are mounted under /api/remote/pouch with the panel's existing
 * `daemon` middleware group, so the agent authenticates with the very same
 * Wings token that already lives on the node (`<token_id>.<token>`).
 */
Route::post('/sync', SyncController::class)->name('pouch.sync');
