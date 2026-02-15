<?php

/*
 * This file is part of the MatesOfMate Organisation.
 *
 * (c) Johannes Wachter <johannes@sulu.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace MatesOfMate\SelfReviewExtension\Git;

/**
 * Value object representing the complete result of a git diff operation.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final readonly class DiffResult
{
    /**
     * @param string            $baseRef Git ref for the base (e.g., 'main', commit SHA)
     * @param string            $headRef Git ref for the head (e.g., 'HEAD', branch name)
     * @param list<ChangedFile> $files   Collection of changed files
     */
    public function __construct(
        public string $baseRef,
        public string $headRef,
        public array $files,
    ) {
    }

    public function getFileCount(): int
    {
        return \count($this->files);
    }

    /**
     * @return list<string>
     */
    public function getFilePaths(): array
    {
        return array_map(
            static fn (ChangedFile $file): string => $file->path,
            $this->files
        );
    }

    public function getFile(string $path): ?ChangedFile
    {
        foreach ($this->files as $file) {
            if ($file->path === $path) {
                return $file;
            }
        }

        return null;
    }

    public function isEmpty(): bool
    {
        return [] === $this->files;
    }

    /**
     * @return array{baseRef: string, headRef: string, files: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'baseRef' => $this->baseRef,
            'headRef' => $this->headRef,
            'files' => array_map(
                static fn (ChangedFile $file): array => $file->toArray(),
                $this->files
            ),
        ];
    }
}
