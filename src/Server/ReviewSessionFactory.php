<?php

/*
 * This file is part of the MatesOfMate Organisation.
 *
 * (c) Johannes Wachter <johannes@sulu.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace MatesOfMate\SelfReviewExtension\Server;

use MatesOfMate\SelfReviewExtension\Git\DiffResult;
use MatesOfMate\SelfReviewExtension\Storage\DatabaseFactory;

/**
 * Factory for creating ReviewSession instances.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
class ReviewSessionFactory
{
    private const START_PORT = 8080;
    private const MAX_PORT_ATTEMPTS = 100;

    public function __construct(
        private readonly DatabaseFactory $databaseFactory,
        private readonly string $projectRoot,
    ) {
    }

    public function create(DiffResult $diff, ?string $context = null, bool $chatEnabled = true): ReviewSession
    {
        $sessionId = $this->generateSessionId();
        $port = $this->findFreePort();
        $publicDir = $this->getPublicDir();
        $database = $this->databaseFactory->create($sessionId);

        return new ReviewSession(
            id: $sessionId,
            port: $port,
            publicDir: $publicDir,
            database: $database,
            diff: $diff,
            filePaths: $diff->getFilePaths(),
            context: $context,
            chatEnabled: $chatEnabled,
        );
    }

    private function generateSessionId(): string
    {
        return bin2hex(random_bytes(8));
    }

    private function findFreePort(): int
    {
        for ($port = self::START_PORT; $port < self::START_PORT + self::MAX_PORT_ATTEMPTS; ++$port) {
            if ($this->isPortFree($port)) {
                return $port;
            }
        }

        throw new \RuntimeException('Could not find a free port');
    }

    private function isPortFree(int $port): bool
    {
        $socket = @fsockopen('localhost', $port, $errno, $errstr, 0.1);

        if (false !== $socket) {
            fclose($socket);

            return false;
        }

        return true;
    }

    private function getPublicDir(): string
    {
        // Try to find the public directory in the extension
        $possiblePaths = [
            __DIR__.'/../../public',
            $this->projectRoot.'/vendor/matesofmate/self-review-extension/public',
        ];

        foreach ($possiblePaths as $path) {
            $realPath = realpath($path);
            if (false !== $realPath && is_dir($realPath)) {
                return $realPath;
            }
        }

        // Fallback to the package public directory
        return __DIR__.'/../../public';
    }
}
