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

use Symfony\Component\Process\Process;

/**
 * Resolves git diffs and file contents using git commands.
 *
 * @internal
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
class GitDiffResolver
{
    public function __construct(
        private readonly DiffParser $parser,
        private readonly string $projectRoot,
    ) {
    }

    /**
     * Resolve the diff between two git refs.
     *
     * @param string       $baseRef Base reference (e.g., 'main', commit SHA)
     * @param string       $headRef Head reference (e.g., 'HEAD', branch name)
     * @param list<string> $paths   Optional list of paths to filter
     */
    public function resolve(string $baseRef = 'main', string $headRef = 'HEAD', array $paths = []): DiffResult
    {
        $diffOutput = $this->runGitDiff($baseRef, $headRef, $paths);
        $files = $this->parser->parse($diffOutput);

        // Load full file contents for each file
        $filesWithContent = [];
        foreach ($files as $file) {
            $oldContent = null;
            $newContent = null;

            if (!$file->isAdded()) {
                $oldContent = $this->getFileContent($baseRef, $file->oldPath ?? $file->path);
            }

            if (!$file->isDeleted()) {
                $newContent = $this->getFileContent($headRef, $file->path);
            }

            $filesWithContent[] = new ChangedFile(
                path: $file->path,
                status: $file->status,
                hunks: $file->hunks,
                oldPath: $file->oldPath,
                oldContent: $oldContent,
                newContent: $newContent,
            );
        }

        return new DiffResult(
            baseRef: $baseRef,
            headRef: $headRef,
            files: $filesWithContent,
        );
    }

    /**
     * Resolve staged changes (git diff --cached).
     *
     * @param string       $baseRef Base reference to compare against (default: HEAD)
     * @param list<string> $paths   Optional list of paths to filter
     */
    public function resolveStaged(string $baseRef = 'HEAD', array $paths = []): DiffResult
    {
        $diffOutput = $this->runStagedDiff($baseRef, $paths);
        $files = $this->parser->parse($diffOutput);

        // Load full file contents for each file
        $filesWithContent = [];
        foreach ($files as $file) {
            $oldContent = null;
            $newContent = null;

            if (!$file->isAdded()) {
                $oldContent = $this->getFileContent($baseRef, $file->oldPath ?? $file->path);
            }

            if (!$file->isDeleted()) {
                $newContent = $this->getStagedFileContent($file->path);
            }

            $filesWithContent[] = new ChangedFile(
                path: $file->path,
                status: $file->status,
                hunks: $file->hunks,
                oldPath: $file->oldPath,
                oldContent: $oldContent,
                newContent: $newContent,
            );
        }

        return new DiffResult(
            baseRef: $baseRef,
            headRef: 'staged',
            files: $filesWithContent,
        );
    }

    /**
     * Check if there are staged changes.
     */
    public function hasStagedChanges(): bool
    {
        $process = new Process(
            ['git', 'diff', '--cached', '--quiet'],
            $this->projectRoot
        );
        $process->run();

        // Exit code 1 means there are differences
        return 1 === $process->getExitCode();
    }

    /**
     * Get the merge base between two refs.
     */
    public function getMergeBase(string $ref1, string $ref2): ?string
    {
        $process = new Process(
            ['git', 'merge-base', $ref1, $ref2],
            $this->projectRoot
        );
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        return trim($process->getOutput());
    }

    /**
     * Check if a ref exists in the repository.
     */
    public function refExists(string $ref): bool
    {
        $process = new Process(
            ['git', 'rev-parse', '--verify', $ref],
            $this->projectRoot
        );
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Get the current branch name.
     */
    public function getCurrentBranch(): ?string
    {
        $process = new Process(
            ['git', 'rev-parse', '--abbrev-ref', 'HEAD'],
            $this->projectRoot
        );
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        $branch = trim($process->getOutput());

        return 'HEAD' === $branch ? null : $branch;
    }

    /**
     * Run git diff --cached command for staged changes.
     *
     * @param list<string> $paths
     */
    private function runStagedDiff(string $baseRef, array $paths): string
    {
        $command = [
            'git',
            'diff',
            '--cached',
            '--unified=5',
            $baseRef,
        ];

        if ([] !== $paths) {
            $command[] = '--';
            $command = array_merge($command, $paths);
        }

        $process = new Process($command, $this->projectRoot);
        $process->setTimeout(60);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(\sprintf('Git diff --cached failed: %s', $process->getErrorOutput()));
        }

        return $process->getOutput();
    }

    /**
     * Get file content from the staging area (index).
     */
    private function getStagedFileContent(string $path): ?string
    {
        $process = new Process(
            ['git', 'show', \sprintf(':%s', $path)],
            $this->projectRoot
        );
        $process->setTimeout(30);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        return $process->getOutput();
    }

    /**
     * Run git diff command and return the output.
     *
     * @param list<string> $paths
     */
    private function runGitDiff(string $baseRef, string $headRef, array $paths): string
    {
        $command = [
            'git',
            'diff',
            '--unified=5',  // 5 lines of context
            \sprintf('%s...%s', $baseRef, $headRef),
        ];

        if ([] !== $paths) {
            $command[] = '--';
            $command = array_merge($command, $paths);
        }

        $process = new Process($command, $this->projectRoot);
        $process->setTimeout(60);
        $process->run();

        if (!$process->isSuccessful()) {
            // Try with .. instead of ... (for direct comparison)
            $command[3] = \sprintf('%s..%s', $baseRef, $headRef);
            $process = new Process($command, $this->projectRoot);
            $process->setTimeout(60);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new \RuntimeException(\sprintf('Git diff failed: %s', $process->getErrorOutput()));
            }
        }

        return $process->getOutput();
    }

    /**
     * Get file content at a specific git ref.
     */
    private function getFileContent(string $ref, string $path): ?string
    {
        $process = new Process(
            ['git', 'show', \sprintf('%s:%s', $ref, $path)],
            $this->projectRoot
        );
        $process->setTimeout(30);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        return $process->getOutput();
    }
}
