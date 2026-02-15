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
 * Value object representing a single hunk in a unified diff.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final readonly class DiffHunk
{
    /**
     * @param int                                                                      $oldStart Starting line in old file
     * @param int                                                                      $oldCount Number of lines in old file
     * @param int                                                                      $newStart Starting line in new file
     * @param int                                                                      $newCount Number of lines in new file
     * @param list<array{type: string, content: string, oldLine: ?int, newLine: ?int}> $lines    Lines with type (context/add/remove) and content
     */
    public function __construct(
        public int $oldStart,
        public int $oldCount,
        public int $newStart,
        public int $newCount,
        public array $lines,
    ) {
    }

    /**
     * @return array{oldStart: int, oldCount: int, newStart: int, newCount: int, lines: list<array{type: string, content: string, oldLine: ?int, newLine: ?int}>}
     */
    public function toArray(): array
    {
        return [
            'oldStart' => $this->oldStart,
            'oldCount' => $this->oldCount,
            'newStart' => $this->newStart,
            'newCount' => $this->newCount,
            'lines' => $this->lines,
        ];
    }
}
