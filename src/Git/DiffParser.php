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
 * Parses unified diff output from git into structured data.
 *
 * @internal
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
class DiffParser
{
    /**
     * Parse raw unified diff output into a list of ChangedFile objects.
     *
     * @return list<ChangedFile>
     */
    public function parse(string $diffOutput): array
    {
        if ('' === trim($diffOutput)) {
            return [];
        }

        $files = [];
        $fileDiffs = $this->splitByFile($diffOutput);

        foreach ($fileDiffs as $fileDiff) {
            $file = $this->parseFileDiff($fileDiff);
            if ($file instanceof ChangedFile) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * Split diff output by file boundaries.
     *
     * @return list<string>
     */
    private function splitByFile(string $diffOutput): array
    {
        // Split by "diff --git" markers
        $parts = preg_split('/^diff --git /m', $diffOutput, -1, \PREG_SPLIT_NO_EMPTY);

        if (false === $parts) {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), $parts),
            static fn (string $part): bool => '' !== $part
        ));
    }

    private function parseFileDiff(string $fileDiff): ?ChangedFile
    {
        $lines = explode("\n", $fileDiff);

        if ([] === $lines) {
            return null;
        }

        // First line contains: a/path b/path
        $firstLine = array_shift($lines);
        $paths = $this->extractPaths($firstLine);

        if (null === $paths) {
            return null;
        }

        [$oldPath, $newPath] = $paths;
        $status = $this->determineStatus($lines, $oldPath, $newPath);
        $hunks = $this->parseHunks($lines);

        // Handle renames
        $finalOldPath = null;
        if (ChangedFile::STATUS_RENAMED === $status && $oldPath !== $newPath) {
            $finalOldPath = $oldPath;
        }

        return new ChangedFile(
            path: $newPath,
            status: $status,
            hunks: $hunks,
            oldPath: $finalOldPath,
        );
    }

    /**
     * Extract old and new paths from the first line of a file diff.
     *
     * @return array{0: string, 1: string}|null
     */
    private function extractPaths(string $firstLine): ?array
    {
        // Format: {prefix}/path/to/file {prefix}/path/to/file
        // Prefixes: a/b (normal), c/i (cached/index), w (working directory)
        if (preg_match('/^[a-z]\/(.+?) [a-z]\/(.+)$/', $firstLine, $matches)) {
            return [$matches[1], $matches[2]];
        }

        return null;
    }

    /**
     * Determine the status of a file change based on diff metadata.
     *
     * @param list<string> $lines
     */
    private function determineStatus(array $lines, string $oldPath, string $newPath): string
    {
        foreach ($lines as $line) {
            if (str_starts_with($line, 'new file mode')) {
                return ChangedFile::STATUS_ADDED;
            }

            if (str_starts_with($line, 'deleted file mode')) {
                return ChangedFile::STATUS_DELETED;
            }

            if (str_starts_with($line, 'similarity index') || str_starts_with($line, 'rename from')) {
                return ChangedFile::STATUS_RENAMED;
            }
        }

        // Check if paths differ (rename without explicit marker)
        if ($oldPath !== $newPath) {
            return ChangedFile::STATUS_RENAMED;
        }

        return ChangedFile::STATUS_MODIFIED;
    }

    /**
     * Parse diff hunks from the file diff lines.
     *
     * @param list<string> $lines
     *
     * @return list<DiffHunk>
     */
    private function parseHunks(array $lines): array
    {
        $hunks = [];
        $currentHunk = null;
        $oldLine = 0;
        $newLine = 0;

        foreach ($lines as $line) {
            // Hunk header: @@ -oldStart,oldCount +newStart,newCount @@
            if (preg_match('/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/', $line, $matches)) {
                if (null !== $currentHunk) {
                    $hunks[] = $currentHunk;
                }

                $oldStart = (int) $matches[1];
                $oldCount = isset($matches[2]) && '' !== $matches[2] ? (int) $matches[2] : 1;
                $newStart = (int) $matches[3];
                $newCount = isset($matches[4]) && '' !== $matches[4] ? (int) $matches[4] : 1;

                $currentHunk = [
                    'oldStart' => $oldStart,
                    'oldCount' => $oldCount,
                    'newStart' => $newStart,
                    'newCount' => $newCount,
                    'lines' => [],
                ];

                $oldLine = $oldStart;
                $newLine = $newStart;

                continue;
            }

            // Skip metadata lines before first hunk
            if (null === $currentHunk) {
                continue;
            }

            // Parse diff lines
            if (str_starts_with($line, '+')) {
                $currentHunk['lines'][] = [
                    'type' => 'add',
                    'content' => substr($line, 1),
                    'oldLine' => null,
                    'newLine' => $newLine++,
                ];
            } elseif (str_starts_with($line, '-')) {
                $currentHunk['lines'][] = [
                    'type' => 'remove',
                    'content' => substr($line, 1),
                    'oldLine' => $oldLine++,
                    'newLine' => null,
                ];
            } elseif (str_starts_with($line, ' ') || '' === $line) {
                // Context line
                $currentHunk['lines'][] = [
                    'type' => 'context',
                    'content' => '' === $line ? '' : substr($line, 1),
                    'oldLine' => $oldLine++,
                    'newLine' => $newLine++,
                ];
            }
            // Ignore other metadata lines (\ No newline at end of file, etc.)
        }

        // Don't forget the last hunk
        if (null !== $currentHunk) {
            $hunks[] = $currentHunk;
        }

        // Convert to DiffHunk objects
        return array_map(
            static fn (array $hunk): DiffHunk => new DiffHunk(
                oldStart: $hunk['oldStart'],
                oldCount: $hunk['oldCount'],
                newStart: $hunk['newStart'],
                newCount: $hunk['newCount'],
                lines: $hunk['lines'],
            ),
            $hunks
        );
    }
}
