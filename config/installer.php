<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Installer Activation
    |--------------------------------------------------------------------------
    | Tri-state: true forces the installer flow on, false forces it off,
    | null = auto (active in every environment except local/testing).
    | The web installer is the bring-up path for fresh production deploys.
    */
    'enabled' => env('APP_INSTALLER_ENABLED'),

    /*
    | Alternative to the lock file: mark the app installed via env
    | (APP_INSTALLED=true). Read through config so config:cache is safe.
    */
    'installed' => env('APP_INSTALLED', false),

    /*
    | Path of the lock file that disables /install after completion.
    | Tests point this at framework/testing to avoid polluting the app.
    */
    'lock_path' => storage_path('app/installed.lock'),

    /*
    | Environment file the installer writes. Tests redirect this.
    */
    'env_path' => null, // null = base_path('.env')

];
