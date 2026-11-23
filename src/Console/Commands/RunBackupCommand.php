<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Console\Commands;

use Illuminate\Console\Command;
use MertAydin\LaravelBackupManager\BackupManager;
use MertAydin\LaravelBackupManager\BackupPreflight;

final class RunBackupCommand extends Command
{
    protected $signature = 'backup:run
        {--profile= : Backup profile name}
        {--database : Include the database}
        {--files : Include application files}
        {--disk= : Override the configured storage disk}';

    protected $description = 'Create and upload a Laravel application backup';

    public function handle(BackupManager $manager, BackupPreflight $preflight): int
    {
        $options = [];
        if ($this->option('profile')) {
            $options['profile'] = $this->option('profile');
        }

        if ($this->option('database') || $this->option('files')) {
            $options['database'] = (bool) $this->option('database');
            $options['files'] = (bool) $this->option('files');
        }

        if ($this->option('disk')) {
            $options['disk'] = $this->option('disk');
        }

        $checks = $preflight->inspect($options);
        if (! $checks['ready']) {
            foreach ($checks['errors'] as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $result = $manager->run($options);

        if (! $result->successful) {
            $this->components->error($result->error ?? 'Backup failed.');

            return self::FAILURE;
        }

        $this->components->info('Backup completed: '.$result->id);
        foreach ($result->artifacts as $name => $path) {
            $this->line($name.': '.$path);
        }

        return self::SUCCESS;
    }
}
