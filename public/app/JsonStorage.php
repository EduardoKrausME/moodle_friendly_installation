<?php
// JSON storage helper with atomic writes and file locking.
namespace app;

class JsonStorage {
    public static function read(string $file, mixed $default = []): mixed {
        if (!file_exists($file)) {
            return $default;
        }

        $raw = file_get_contents($file);
        if ($raw == false || trim($raw) == '') {
            return $default;
        }

        $data = json_decode($raw, true);
        return json_last_error() == JSON_ERROR_NONE ? $data : $default;
    }

    public static function write(string $file, mixed $data): void {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        $owner = file_exists($file) ? @fileowner($file) : false;
        $group = file_exists($file) ? @filegroup($file) : false;

        $lockfile = $file . '.lock';
        $lock = fopen($lockfile, 'c');
        if (!$lock) {
            throw new RuntimeException('Cannot open lock file: ' . $lockfile);
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Cannot lock file: ' . $lockfile);
            }

            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json == false) {
                throw new RuntimeException('Cannot encode JSON for: ' . $file);
            }

            $tmp = $file . '.tmp.' . bin2hex(random_bytes(6));
            if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) == false) {
                throw new RuntimeException('Cannot write temporary file: ' . $tmp);
            }

            chmod($tmp, 0640);
            if ($owner != false) {
                @chown($tmp, $owner);
            }
            if ($group != false) {
                @chgrp($tmp, $group);
            }
            rename($tmp, $file);
            flock($lock, LOCK_UN);
        } finally {
            fclose($lock);
        }
    }

    public static function update(string $file, callable $callback, mixed $default = []): mixed {
        $data = self::read($file, $default);
        $newdata = $callback($data);
        self::write($file, $newdata);
        return $newdata;
    }
}
