<?php

use Illuminate\Support\Str;

return [

    'name' => env('HORIZON_NAME'),

    'domain' => env('HORIZON_DOMAIN'),

    'path' => env('HORIZON_PATH', 'horizon'),

    'use' => 'default',

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug((string) env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    'middleware' => ['web', 'auth', 'can:admin'],

    'waits' => [
        'redis:default' => 60,
    ],

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'silenced' => [

    ],

    'silenced_tags' => [

    ],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,

    'memory_limit' => 64,

    'defaults' => [
        'provider' => [
            'connection' => 'redis',
            'queue' => ['provider'],
            'balance' => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 3,
            'maxTime' => 3600,
            'maxJobs' => 1000,
            'memory' => 256,
            'tries' => 15,
            'timeout' => 60,
            'nice' => 0,
        ],
        'events' => [
            'connection' => 'redis',
            'queue' => ['events', 'default'],
            'balance' => false,
            'maxProcesses' => 1,
            'memory' => 128,
            'tries' => 5,
            'timeout' => 60,
        ],
    ],
    'environments' => [
        'production' => ['provider' => [], 'events' => []],
        'local' => ['provider' => [], 'events' => []],
    ],

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
