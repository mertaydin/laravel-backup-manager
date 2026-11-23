<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Storage;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

final class StorageSettingsRepository
{
    private const KEY = 'configuration';

    /** @return array<string, mixed> */
    public function all(): array
    {
        $value = DB::table('backup_manager_storage_settings')->where('key', self::KEY)->value('value');
        if (! is_string($value) || $value === '') {
            return ['active_disk' => 'local', 'disks' => []];
        }

        try {
            $settings = json_decode(Crypt::decryptString($value), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            throw new RuntimeException('Backup Manager storage settings could not be decrypted. Check APP_KEY.', previous: $exception);
        }

        return is_array($settings) ? $settings : ['active_disk' => 'local', 'disks' => []];
    }

    /** @param array<string, mixed> $settings */
    public function save(array $settings): void
    {
        try {
            $encrypted = Crypt::encryptString(json_encode($settings, JSON_THROW_ON_ERROR));
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode Backup Manager storage settings.', previous: $exception);
        }

        $query = DB::table('backup_manager_storage_settings')->where('key', self::KEY);
        if ($query->exists()) {
            $query->update(['value' => $encrypted, 'updated_at' => now()]);

            return;
        }

        DB::table('backup_manager_storage_settings')->insert([
            'key' => self::KEY,
            'value' => $encrypted,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $disk */
    public function putDisk(string $name, array $disk): void
    {
        $settings = $this->all();
        $settings['disks'][$name] = $disk;
        $this->save($settings);
    }

    public function setActiveDisk(string $name): void
    {
        $settings = $this->all();
        if (! isset($settings['disks'][$name]) && $name !== 'local') {
            throw new RuntimeException("Storage disk [{$name}] is not configured.");
        }

        $settings['active_disk'] = $name;
        $this->save($settings);
    }
}
