<?php

/*
 * This file is part of the MatesOfMate Organisation.
 *
 * (c) Johannes Wachter <johannes@sulu.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace MatesOfMate\SelfReviewExtension\Storage;

/**
 * Factory for creating Database instances with unique file paths.
 *
 * @internal
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
class DatabaseFactory
{
    /**
     * Create a new Database instance with a temporary file.
     */
    public function create(string $sessionId): Database
    {
        $tmpDir = sys_get_temp_dir();
        $dbPath = \sprintf('%s/self-review-%s.sqlite', $tmpDir, $sessionId);

        return new Database($dbPath);
    }

    /**
     * Get the database path for a session.
     */
    public function getPath(string $sessionId): string
    {
        return \sprintf('%s/self-review-%s.sqlite', sys_get_temp_dir(), $sessionId);
    }

    /**
     * Delete the database file for a session.
     */
    public function delete(string $sessionId): void
    {
        $path = $this->getPath($sessionId);

        if (file_exists($path)) {
            unlink($path);
        }
    }

    /**
     * Check if a database exists for a session.
     */
    public function exists(string $sessionId): bool
    {
        return file_exists($this->getPath($sessionId));
    }
}
