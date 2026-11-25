<?php

return [
    'default' => env('BACKUP_MANAGER_PROFILE', 'default'),
    'default_driver' => 'local',
    'ui' => [
        'enabled' => env('BACKUP_MANAGER_UI_ENABLED', true),
        'prefix' => env('BACKUP_MANAGER_UI_PREFIX', 'backup-manager'),
        'asset_version' => env('BACKUP_MANAGER_ASSET_VERSION', '0.8.14'),
        'middleware' => ['web', 'auth'],
    ],
    'naming' => [
        'template' => env('BACKUP_MANAGER_NAME_TEMPLATE', ':app-:date-:time'),
    ],
    'integrity' => [
        'verify_after_upload' => env('BACKUP_MANAGER_VERIFY_AFTER_UPLOAD', true),
    ],
    'lock_seconds' => (int) env('BACKUP_MANAGER_LOCK_SECONDS', 21600),
    'dedupe_window_seconds' => (int) env('BACKUP_MANAGER_DEDUPE_WINDOW_SECONDS', 300),
    'preflight' => [
        'minimum_free_bytes' => (int) env('BACKUP_MANAGER_MINIMUM_FREE_BYTES', 104857600),
    ],
    'retry' => [
        'max_attempts' => (int) env('BACKUP_MANAGER_RETRY_ATTEMPTS', 1),
        'delay_seconds' => (int) env('BACKUP_MANAGER_RETRY_DELAY', 30),
    ],
    'upload' => [
        'multipart' => [
            'enabled' => (bool) env('BACKUP_MANAGER_MULTIPART_ENABLED', true),
            'threshold_bytes' => (int) env('BACKUP_MANAGER_MULTIPART_THRESHOLD', 67108864),
            'part_size_bytes' => (int) env('BACKUP_MANAGER_MULTIPART_PART_SIZE', 8388608),
        ],
    ],
    'notifications' => [
        'enabled' => (bool) env('BACKUP_MANAGER_NOTIFICATIONS_ENABLED', false),
        'mail_to' => array_values(array_filter(array_map('trim', explode(',', (string) env('BACKUP_MANAGER_NOTIFICATION_EMAILS', ''))))),
        'slack_webhook' => env('BACKUP_MANAGER_SLACK_WEBHOOK'),
    ],
    'mysql' => [
        'dump_binary' => env('BACKUP_MANAGER_MYSQLDUMP_BINARY', 'mysqldump'),
        'restore_binary' => env('BACKUP_MANAGER_MYSQL_BINARY', 'mysql'),
    ],
    'postgresql' => [
        'dump_binary' => env('BACKUP_MANAGER_PG_DUMP_BINARY', 'pg_dump'),
        'restore_binary' => env('BACKUP_MANAGER_PSQL_BINARY', 'psql'),
    ],

    'profiles' => [
        'default' => [
            'database' => [
                'enabled' => true,
                'connection' => env('BACKUP_DB_CONNECTION', env('DB_CONNECTION', 'mysql')),
                'tables' => [],
                'exclude_tables' => [],
                'dump_binary' => env('BACKUP_MANAGER_MYSQLDUMP_BINARY', 'mysqldump'),
                'postgres_dump_binary' => env('BACKUP_MANAGER_PG_DUMP_BINARY', 'pg_dump'),
                'postgres_restore_binary' => env('BACKUP_MANAGER_PSQL_BINARY', 'psql'),
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
                'local_disk' => env('BACKUP_MANAGER_LOCAL_DISK', 'local'),
                'disks' => array_values(array_filter(array_map('trim', explode(',', (string) env('BACKUP_MANAGER_TARGET_DISKS', ''))))),
                'path' => 'backup-manager/backups',
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
