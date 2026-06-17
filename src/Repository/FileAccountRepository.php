<?php

declare(strict_types=1);

namespace Ebanx\Repository;

/**
 * File-backed implementation used by the running HTTP server.
 *
 * Why does an "in-memory" challenge need a file? PHP runs on a shared-nothing
 * model: every request re-executes the script from a clean slate, so a plain
 * array would be empty again on the very next request. This class bridges that
 * gap by keeping the state in a single JSON file, guarded by flock() so a
 * read-modify-write is never interleaved.
 *
 * This is NOT durability in the database sense (the spec explicitly does not
 * require it) -- it is the minimum needed to keep state alive between requests
 * of the same running process. The whole concern is hidden behind
 * AccountRepository, so the business logic never knows it exists.
 */
final class FileAccountRepository implements AccountRepository
{
    public function __construct(private readonly string $path)
    {
    }

    public function find(string $id): ?int
    {
        return $this->read()[$id] ?? null;
    }

    /**
     * Run $apply against the current state under an exclusive lock and persist
     * the result. The lock spans the whole read-modify-write, so concurrent
     * requests cannot clobber each other and a multi-account change (a transfer)
     * is all-or-nothing. If $apply throws, the file is left untouched.
     *
     * @template T
     * @param callable(array<string, int> &): T $apply
     * @return T
     */
    public function transaction(callable $apply): mixed
    {
        $dir = \dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Unable to create account store directory at {$dir}");
        }

        $handle = fopen($this->path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open account store at {$this->path}");
        }

        try {
            flock($handle, LOCK_EX);
            $state = $this->decode(stream_get_contents($handle) ?: '');

            // Mutates $state by reference; an exception here skips the write below.
            $result = $apply($state);

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR));
            fflush($handle);

            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function reset(): void
    {
        $this->transaction(static function (array &$state): void {
            $state = [];
        });
    }

    /**
     * @return array<string, int>
     */
    private function read(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $handle = fopen($this->path, 'r');
        if ($handle === false) {
            return [];
        }

        try {
            flock($handle, LOCK_SH);
            $state = $this->decode(stream_get_contents($handle) ?: '');
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return $state;
    }

    /**
     * @return array<string, int>
     */
    private function decode(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? $decoded : [];
    }
}
