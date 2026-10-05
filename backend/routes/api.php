<?php

use Illuminate\Support\Facades\Route;

/*
| Public API, versioned by URL prefix: /api/v1/... (docs/api/README.md).
| A future breaking version gets its own file (routes/api_v2.php) mounted here.
*/

Route::prefix('v1')
    ->name('api.v1.')
    ->group(base_path('routes/api_v1.php'));
