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
use Mcp\Exception\ClientException;
use Mcp\Schema\Content\TextContent;
use Mcp\Server\RequestContext;

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
        description: 'Check if a human review session is complete and get the results. Returns either "waiting" status if review is not done, or the complete review with verdict and comments. Call this periodically or after user indicates they are done reviewing.'
    )]
    public function result(
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

            // Check if review was submitted
            if (!$session->isSubmitted()) {
                return $this->getFormatter()->formatWaiting($session);
            }

            // Collect and return results
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
        } catch (\Throwable $e) {
            return $this->getFormatter()->formatError($e->getMessage(), $session_id);
        }
    }

    #[McpTool(
        name: 'self-review-chat',
        description: 'Answer pending questions from reviewer about the code changes. Uses MCP sampling to generate answers with context about what you changed.'
    )]
    public function chat(
        #[Schema(
            description: 'Session ID returned by self-review-start'
        )]
        string $session_id,
        RequestContext $context,
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

            $clientGateway = $context->getClientGateway();
            $answered = 0;

            foreach ($questions as $question) {
                $database->updateChatMessageStatus($question['id'], 'processing');

                // Build context-aware prompt
                $systemPrompt = $this->buildChatSystemPrompt($session, $question);

                try {
                    $result = $clientGateway->sample(
                        $question['content'],
                        1000,
                        120,
                        ['systemPrompt' => $systemPrompt]
                    );

                    $content = $result->content;
                    $answerText = $content instanceof TextContent
                        ? $content->text
                        : 'Unable to process response (non-text content)';

                    $database->addChatAnswer(
                        $session_id,
                        $question['id'],
                        $answerText
                    );
                    ++$answered;
                } catch (ClientException $e) {
                    $database->updateChatMessageStatus(
                        $question['id'],
                        'error',
                        $e->getMessage()
                    );
                }
            }

            return $this->getFormatter()->formatChatResult($answered, \count($questions));
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

    /**
     * Build context-aware system prompt for chat questions.
     *
     * @param array{id: int, content: string, file_context: ?string, line_context: ?int, status: string} $question
     */
    private function buildChatSystemPrompt(ReviewSession $session, array $question): string
    {
        $prompt = 'You are explaining code changes you made. ';
        $prompt .= 'Context: '.($session->getContext() ?: 'Code review session')."\n\n";

        if (null !== $question['file_context']) {
            $prompt .= \sprintf('The question is about file: %s', $question['file_context']);
            if (null !== $question['line_context']) {
                $prompt .= \sprintf(' at line %d', $question['line_context']);
            }
            $prompt .= "\n\n";
        }

        return $prompt."Answer the reviewer's question concisely and helpfully.";
    }
}
