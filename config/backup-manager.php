<?php

return [
    'default' => env('BACKUP_MANAGER_PROFILE', 'default'),
    'default_driver' => 'local',
    'ui' => [
        'enabled' => env('BACKUP_MANAGER_UI_ENABLED', true),
        'prefix' => env('BACKUP_MANAGER_UI_PREFIX', 'backup-manager'),
        'middleware' => ['web', 'auth'],
    ],
    'mysql' => [
        'dump_binary' => env('BACKUP_MANAGER_MYSQLDUMP_BINARY', 'mysqldump'),
    ],

    'profiles' => [
        'default' => [
            'database' => [
                'enabled' => true,
                'connection' => env('BACKUP_DB_CONNECTION', env('DB_CONNECTION', 'mysql')),
                'tables' => [],
                'exclude_tables' => [],
                'dump_binary' => env('BACKUP_MANAGER_MYSQLDUMP_BINARY', 'mysqldump'),
            ],
            'files' => [
                'enabled' => false,
                'paths' => [],
                'exclude' => [
                    'vendor',
                    'node_modules',
                    'storage/framework/cache',
                    'storage/framework/sessions',
                    'storage/framework/views',
                    'storage/logs',
                    'storage/app/backup-manager',
                    '.git',
                ],
                'include_dotfiles' => false,
                'exclude_env' => true,
            ],
            'storage' => [
                'disk' => env('BACKUP_MANAGER_DISK', 'local'),
                'path' => 'backups',
            ],
            'encryption' => [
                'enabled' => false,
                'key' => env('BACKUP_MANAGER_ENCRYPTION_KEY'),
                'key_id' => env('BACKUP_MANAGER_ENCRYPTION_KEY_ID'),
            ],
            'retention' => [
                'enabled' => false,
                'keep_days' => 30,
                'keep_last' => 10,
            ],
        ],
    ],
    'queue' => [
        'connection' => env('BACKUP_MANAGER_QUEUE_CONNECTION'),
        'queue' => env('BACKUP_MANAGER_QUEUE'),
    ],
    'temporary_directory' => storage_path('app/backup-manager/tmp'),
];
