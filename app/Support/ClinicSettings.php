<?php

namespace App\Support;

class ClinicSettings
{
    public static function all(): array
    {
        $defaults = config('clinic', []);
        if (app()->runningUnitTests()) {
            return $defaults;
        }
        $path = storage_path('app/clinic-settings.json');
        if (! is_file($path)) {
            return $defaults;
        }
        $file = fopen($path, 'rb');
        if (! $file) {
            return $defaults;
        }
        flock($file, LOCK_SH);
        $raw = stream_get_contents($file);
        flock($file, LOCK_UN);
        fclose($file);

        return array_replace($defaults, json_decode($raw, true) ?: []);
    }

    public static function valueFor(string $key, string $default = ''): string
    {
        return (string) (self::all()[$key] ?? $default);
    }

    public static function save(array $values): void
    {
        if (app()->runningUnitTests()) {
            config(['clinic' => array_replace(config('clinic', []), $values)]);

            return;
        }
        $file = fopen(storage_path('app/clinic-settings.json'), 'c+');
        if (! $file || ! flock($file, LOCK_EX)) {
            throw new \RuntimeException('Unable to save clinic configuration.');
        }
        $current = json_decode(stream_get_contents($file), true) ?: [];
        $json = json_encode(array_replace($current, $values), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        rewind($file);
        ftruncate($file, 0);
        fwrite($file, $json);
        fflush($file);
        flock($file,LOCK_UN);
        fclose($file);
    }
}
