<?php

use Illuminate\Support\Facades\Route;
use studioespresso\varnish\controllers\EsiController;

// `/actions/varnish/esi/render`, fetched by Varnish for `<esi:include>` tags (anonymous: it's the visitor's request)
Route::get('esi/render', [EsiController::class, 'render']);
