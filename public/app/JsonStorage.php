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
     * @return mixed
     */
    public static function read(string $file): mixed {
        if (!file_exists($file)) {
            return [];
        }

        $raw = file_get_contents($file);
        if (!$raw || trim($raw) == "") {
            return [];
        }

        $data = json_decode($raw, true);
        return json_last_error() == JSON_ERROR_NONE ? $data : [];
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

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!$json) {
            throw new RuntimeException("Cannot encode JSON for: {$file}");
        }

        if (!file_put_contents($file, $json . PHP_EOL, LOCK_EX)) {
            throw new RuntimeException("Cannot write temporary file: {$file}");
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
