<?php

/*
 * This file is part of the MatesOfMate Organisation.
 *
 * (c) Johannes Wachter <johannes@sulu.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace MatesOfMate\SelfReviewExtension\Capability;

use MatesOfMate\SelfReviewExtension\Formatter\ToonFormatter;
use MatesOfMate\SelfReviewExtension\Git\DiffParser;
use MatesOfMate\SelfReviewExtension\Git\GitDiffResolver;
use MatesOfMate\SelfReviewExtension\Server\ReviewSession;
use MatesOfMate\SelfReviewExtension\Server\ReviewSessionFactory;
use MatesOfMate\SelfReviewExtension\Storage\DatabaseFactory;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;

/**
 * Human-in-the-loop code review tool with two non-blocking MCP methods.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
class SelfReviewTool
{
    /**
     * Static storage for sessions to persist across tool instances.
     * The MCP SDK creates a new instance for each tool call, so we need static storage.
     *
     * @var array<string, ReviewSession>
     */
    private static array $sessions = [];

    public function __construct(private ?GitDiffResolver $diffResolver = null, private ?ReviewSessionFactory $sessionFactory = null, private ?ToonFormatter $formatter = null)
    {
    }

    /**
     * @param list<string> $paths
     */
    #[McpTool(
        name: 'self-review-start',
        description: 'Start a human code review session. Opens a browser window for the user to review git changes and add comments. Returns immediately with a session ID - use self-review-result to check for completion. Use for: getting human feedback on code changes, code review before commit/merge, quality assurance. Set staged=true to review staged (git add) changes instead of comparing refs.'
    )]
    public function start(
        #[Schema(
            description: 'Base git reference to compare from (e.g., "main", "HEAD~5", commit SHA). Defaults to "HEAD".'
        )]
        string $base_ref = 'HEAD',
        #[Schema(
            description: 'Head git reference to compare to (e.g., "HEAD", branch name). Defaults to "HEAD". Ignored when staged=true.'
        )]
        string $head_ref = 'HEAD',
        #[Schema(
            description: 'Set to true to review staged changes (git diff --cached) instead of comparing refs. This is useful for reviewing changes before committing.'
        )]
        bool $staged = false,
        #[Schema(
            description: 'Optional list of file paths to filter the diff. Empty array means all changed files.'
        )]
        array $paths = [],
        #[Schema(
            description: 'Context message to show the reviewer. Explain what you changed and what kind of feedback you need.'
        )]
        string $context = '',
    ): string {
        try {
            /** @var list<string> $pathList */
            $pathList = array_values(array_filter(
                array_map(strval(...), $paths),
                static fn (string $p): bool => '' !== $p
            ));

            // Resolve the diff based on mode
            if ($staged) {
                // Review staged changes
                if (!$this->getDiffResolver()->hasStagedChanges()) {
                    return $this->getFormatter()->formatError('No staged changes found. Use "git add" to stage changes first.');
                }

                $diff = $this->getDiffResolver()->resolveStaged($base_ref, $pathList);
            } else {
                // Validate refs exist
                if (!$this->getDiffResolver()->refExists($base_ref)) {
                    return $this->getFormatter()->formatError(
                        \sprintf('Base ref "%s" does not exist', $base_ref)
                    );
                }

                if (!$this->getDiffResolver()->refExists($head_ref)) {
                    return $this->getFormatter()->formatError(
                        \sprintf('Head ref "%s" does not exist', $head_ref)
                    );
                }

                $diff = $this->getDiffResolver()->resolve($base_ref, $head_ref, $pathList);

                if ($diff->isEmpty()) {
                    // Check if there are staged changes
                    if ($this->getDiffResolver()->hasStagedChanges()) {
                        return $this->getFormatter()->formatError('No changes found between refs, but there are staged changes. Try using staged=true.');
                    }

                    return $this->getFormatter()->formatError('No changes found between the specified refs');
                }
            }

            if ($diff->isEmpty()) {
                return $this->getFormatter()->formatError('No changes found');
            }

            // Create session and start server
            $session = $this->getSessionFactory()->create(
                $diff,
                '' !== $context ? $context : null
            );

            // Store session for later retrieval
            self::$sessions[$session->getId()] = $session;

            return $this->getFormatter()->formatStartResponse($session);
        } catch (\Throwable $e) {
            return $this->getFormatter()->formatError($e->getMessage());
        }
    }

    #[McpTool(
        name: 'self-review-result',
        description: 'Check if a human review session is complete and get the results. Polls for events (review submitted or new chat questions) until timeout. Returns review results, pending questions requiring answers, or waiting status.'
    )]
    public function result(
        #[Schema(
            description: 'Session ID returned by self-review-start'
        )]
        string $session_id,
        #[Schema(
            description: 'Maximum seconds to wait for events (review submission or new questions). Default 60, max 300.'
        )]
        int $timeout = 60,
    ): string {
        try {
            // Clamp timeout to reasonable bounds
            $timeout = max(1, min(300, $timeout));

            // Check if session exists
            if (!isset(self::$sessions[$session_id])) {
                return $this->getFormatter()->formatError(
                    'Session not found. It may have expired or been closed.',
                    $session_id
                );
            }

            $session = self::$sessions[$session_id];
            $database = $session->getDatabase();
            $startTime = time();

            // Poll for events until timeout
            while ((time() - $startTime) < $timeout) {
                // Check if session expired
                if ($session->isExpired()) {
                    $session->shutdown();
                    unset(self::$sessions[$session_id]);

                    return $this->getFormatter()->formatError(
                        'Session expired (TTL: 1 hour)',
                        $session_id
                    );
                }

                // Check if server is still running
                if (!$session->isRunning()) {
                    unset(self::$sessions[$session_id]);

                    return $this->getFormatter()->formatError(
                        'Review server stopped unexpectedly',
                        $session_id
                    );
                }

                // Check if review was submitted
                if ($session->isSubmitted()) {
                    $result = $session->collectResult();

                    if (null === $result) {
                        return $this->getFormatter()->formatError(
                            'Failed to collect review results',
                            $session_id
                        );
                    }

                    // Cleanup session
                    $session->shutdown();
                    unset(self::$sessions[$session_id]);

                    return $this->getFormatter()->formatResult($result);
                }

                // Check for pending questions
                $questions = $database->getPendingQuestions($session_id);
                if ([] !== $questions) {
                    return $this->getFormatter()->formatWaitingWithQuestions(
                        $session,
                        $questions
                    );
                }

                // Sleep before next poll (1 second)
                usleep(1000000);
            }

            // Timeout reached, return waiting status
            return $this->getFormatter()->formatWaiting($session);
        } catch (\Throwable $e) {
            return $this->getFormatter()->formatError($e->getMessage(), $session_id);
        }
    }

    #[McpTool(
        name: 'self-review-chat',
        description: 'Get pending questions from reviewer about the code changes. Returns questions that need answers. Use self-review-answer to submit your response to each question.'
    )]
    public function chat(
        #[Schema(
            description: 'Session ID returned by self-review-start'
        )]
        string $session_id,
    ): string {
        try {
            // Check if session exists
            if (!isset(self::$sessions[$session_id])) {
                return $this->getFormatter()->formatError(
                    'Session not found. It may have expired or been closed.',
                    $session_id
                );
            }

            $session = self::$sessions[$session_id];

            // Check if session expired
            if ($session->isExpired()) {
                $session->shutdown();
                unset(self::$sessions[$session_id]);

                return $this->getFormatter()->formatError(
                    'Session expired (TTL: 1 hour)',
                    $session_id
                );
            }

            // Check if server is still running
            if (!$session->isRunning()) {
                unset(self::$sessions[$session_id]);

                return $this->getFormatter()->formatError(
                    'Review server stopped unexpectedly',
                    $session_id
                );
            }

            $database = $session->getDatabase();
            $questions = $database->getPendingQuestions($session_id);

            if ([] === $questions) {
                return $this->getFormatter()->formatNoPendingQuestions($session_id);
            }

            return $this->getFormatter()->formatPendingQuestions($questions, $session->getContext());
        } catch (\Throwable $e) {
            return $this->getFormatter()->formatError($e->getMessage(), $session_id);
        }
    }

    #[McpTool(
        name: 'self-review-answer',
        description: 'Submit an answer to a reviewer question. Call self-review-chat first to get pending questions, then use this tool to answer each one.'
    )]
    public function answer(
        #[Schema(
            description: 'Session ID returned by self-review-start'
        )]
        string $session_id,
        #[Schema(
            description: 'Question ID from self-review-chat response'
        )]
        int $question_id,
        #[Schema(
            description: 'Your answer to the reviewer question'
        )]
        string $answer,
    ): string {
        try {
            // Check if session exists
            if (!isset(self::$sessions[$session_id])) {
                return $this->getFormatter()->formatError(
                    'Session not found. It may have expired or been closed.',
                    $session_id
                );
            }

            $session = self::$sessions[$session_id];

            // Check if session expired
            if ($session->isExpired()) {
                $session->shutdown();
                unset(self::$sessions[$session_id]);

                return $this->getFormatter()->formatError(
                    'Session expired (TTL: 1 hour)',
                    $session_id
                );
            }

            // Check if server is still running
            if (!$session->isRunning()) {
                unset(self::$sessions[$session_id]);

                return $this->getFormatter()->formatError(
                    'Review server stopped unexpectedly',
                    $session_id
                );
            }

            $database = $session->getDatabase();

            // Add the answer
            $database->addChatAnswer($session_id, $question_id, $answer);

            return $this->getFormatter()->formatAnswerSubmitted($question_id);
        } catch (\Throwable $e) {
            return $this->getFormatter()->formatError($e->getMessage(), $session_id);
        }
    }

    private function getDiffResolver(): GitDiffResolver
    {
        if (!$this->diffResolver instanceof GitDiffResolver) {
            $projectRoot = (string) getcwd();
            $this->diffResolver = new GitDiffResolver(new DiffParser(), $projectRoot);
        }

        return $this->diffResolver;
    }

    private function getSessionFactory(): ReviewSessionFactory
    {
        if (!$this->sessionFactory instanceof ReviewSessionFactory) {
            $projectRoot = (string) getcwd();
            $this->sessionFactory = new ReviewSessionFactory(new DatabaseFactory(), $projectRoot);
        }

        return $this->sessionFactory;
    }

    private function getFormatter(): ToonFormatter
    {
        if (!$this->formatter instanceof ToonFormatter) {
            $this->formatter = new ToonFormatter();
        }

        return $this->formatter;
    }
}
