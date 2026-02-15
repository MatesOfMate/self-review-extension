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
use MatesOfMate\SelfReviewExtension\Output\ReviewResult;
use MatesOfMate\SelfReviewExtension\Storage\Database;
use Symfony\Component\Process\Process;

/**
 * Encapsulates a single review session lifecycle.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
class ReviewSession
{
    private const TTL_SECONDS = 3600; // 1 hour

    private Process $serverProcess;

    private readonly int $createdAt;

    /**
     * @param list<string> $filePaths
     */
    public function __construct(
        private readonly string $id,
        private readonly int $port,
        private readonly string $publicDir,
        private readonly Database $database,
        private readonly DiffResult $diff,
        private readonly array $filePaths,
        private readonly ?string $context = null,
    ) {
        $this->createdAt = time();
        $this->database->createSession($this->id, $this->diff, $this->context);
        $this->startServer();
        $this->openBrowser();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUrl(): string
    {
        return \sprintf('http://localhost:%d', $this->port);
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getFileCount(): int
    {
        return \count($this->filePaths);
    }

    /**
     * @return list<string>
     */
    public function getFilePaths(): array
    {
        return $this->filePaths;
    }

    public function isSubmitted(): bool
    {
        return $this->database->isSubmitted($this->id);
    }

    public function commentCount(): int
    {
        return $this->database->commentCount($this->id);
    }

    public function collectResult(): ?ReviewResult
    {
        return $this->database->collectResult($this->id);
    }

    public function getDatabase(): Database
    {
        return $this->database;
    }

    public function getContext(): ?string
    {
        return $this->context;
    }

    public function isExpired(): bool
    {
        return (time() - $this->createdAt) > self::TTL_SECONDS;
    }

    public function isRunning(): bool
    {
        return $this->serverProcess->isRunning();
    }

    public function shutdown(): void
    {
        if ($this->serverProcess->isRunning()) {
            $this->serverProcess->stop(3);
        }
    }

    private function startServer(): void
    {
        $routerPath = __DIR__.'/router.php';

        $this->serverProcess = new Process([
            \PHP_BINARY,
            '-S',
            \sprintf('localhost:%d', $this->port),
            $routerPath,
        ], $this->publicDir, [
            'SELF_REVIEW_SESSION_ID' => $this->id,
            'SELF_REVIEW_DB_PATH' => \sprintf('%s/self-review-%s.sqlite', sys_get_temp_dir(), $this->id),
        ]);

        $this->serverProcess->setTimeout(null);
        $this->serverProcess->start();

        // Give the server a moment to start
        usleep(100000); // 100ms

        if (!$this->serverProcess->isRunning()) {
            throw new \RuntimeException(\sprintf('Failed to start server: %s', $this->serverProcess->getErrorOutput()));
        }
    }

    private function openBrowser(): void
    {
        $url = $this->getUrl();

        // Detect OS and open browser
        if (\PHP_OS_FAMILY === 'Darwin') {
            $command = ['open', $url];
        } elseif (\PHP_OS_FAMILY === 'Windows') {
            $command = ['start', '', $url];
        } else {
            $command = ['xdg-open', $url];
        }

        $process = new Process($command);
        $process->setTimeout(5);
        $process->run();
    }
}
