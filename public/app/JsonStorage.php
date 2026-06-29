<?php
// JSON storage helper with atomic writes and file locking.
namespace app;

use RuntimeException;

/**
 * Class JsonStorage
 */
class JsonStorage {
    /**
     * Function read
     *
     * @param string $file
     * @param mixed $default
     * @return mixed
     */
    public static function read(string $file, mixed $default = []): mixed {
        if (!file_exists($file)) {
            return $default;
        }

        $raw = file_get_contents($file);
        if (!$raw || trim($raw) == '') {
            return $default;
        }

        $data = json_decode($raw, true);
        return json_last_error() == JSON_ERROR_NONE ? $data : $default;
    }

    /**
     * Function write
     *
     * @param string $file
     * @param mixed $data
     * @return void
     * @throws \Random\RandomException
     */
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
            if (!$json) {
                throw new RuntimeException('Cannot encode JSON for: ' . $file);
            }

            $tmp = $file . '.tmp.' . bin2hex(random_bytes(6));
            if (!file_put_contents($tmp, $json . PHP_EOL, LOCK_EX)) {
                throw new RuntimeException('Cannot write temporary file: ' . $tmp);
            }

            chmod($tmp, 0640);
            if ($owner) {
                @chown($tmp, $owner);
            }
            if ($group) {
                @chgrp($tmp, $group);
            }
            rename($tmp, $file);
            flock($lock, LOCK_UN);
        } finally {
            fclose($lock);
        }
    }

    /**
     * Function update
     *
     * @param string $file
     * @param callable $callback
     * @param mixed $default
     * @return mixed
     * @throws \Random\RandomException
     */
    public static function update(string $file, callable $callback, mixed $default = []): mixed {
        $data = self::read($file, $default);
        $newdata = $callback($data);
        self::write($file, $newdata);
        return $newdata;
    }
}
