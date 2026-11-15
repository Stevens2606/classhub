<?php

use Illuminate\Support\Facades\Route;

// The demo web interface is a static app in public/app. Open http://localhost:8000
Route::redirect('/', '/app/index.html');
