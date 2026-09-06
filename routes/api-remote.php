<?php

use Illuminate\Support\Facades\Route;
use Wan0v\Pouch\Http\Controllers\Remote\SyncController;

/*
 * Routes for the Pouch agent running on a node.
 *
 * Mounted under /api/remote/pouch by PouchRouteProvider, behind the plugin's
 * own AuthenticatePouchAgent middleware.
 */
Route::post('/sync', SyncController::class)->name('pouch.sync');
