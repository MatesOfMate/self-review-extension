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
 * Value object representing a changed file in a diff.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final readonly class ChangedFile
{
    public const STATUS_ADDED = 'added';
    public const STATUS_MODIFIED = 'modified';
    public const STATUS_DELETED = 'deleted';
    public const STATUS_RENAMED = 'renamed';

    /**
     * @param string         $path       File path (new path if renamed)
     * @param string         $status     One of the STATUS_* constants
     * @param list<DiffHunk> $hunks      Diff hunks for this file
     * @param string|null    $oldPath    Original path (for renames)
     * @param string|null    $oldContent Full content of old version
     * @param string|null    $newContent Full content of new version
     */
    public function __construct(
        public string $path,
        public string $status,
        public array $hunks = [],
        public ?string $oldPath = null,
        public ?string $oldContent = null,
        public ?string $newContent = null,
    ) {
    }

    public function isAdded(): bool
    {
        return self::STATUS_ADDED === $this->status;
    }

    public function isDeleted(): bool
    {
        return self::STATUS_DELETED === $this->status;
    }

    public function isModified(): bool
    {
        return self::STATUS_MODIFIED === $this->status;
    }

    public function isRenamed(): bool
    {
        return self::STATUS_RENAMED === $this->status;
    }

    /**
     * @return array{path: string, status: string, oldPath: ?string, hunks: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'status' => $this->status,
            'oldPath' => $this->oldPath,
            'hunks' => array_map(
                static fn (DiffHunk $hunk): array => $hunk->toArray(),
                $this->hunks
            ),
        ];
    }
}
