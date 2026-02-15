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

use MatesOfMate\SelfReviewExtension\Git\DiffResult;
use MatesOfMate\SelfReviewExtension\Output\ReviewComment;
use MatesOfMate\SelfReviewExtension\Output\ReviewResult;

/**
 * PDO/SQLite wrapper for review session storage.
 *
 * @internal
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
class Database
{
    private readonly \PDO $pdo;

    public function __construct(string $dbPath)
    {
        $this->pdo = new \PDO(
            \sprintf('sqlite:%s', $dbPath),
            null,
            null,
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]
        );

        $this->initializeSchema();
    }

    /**
     * Create a new review session.
     */
    public function createSession(string $id, DiffResult $diff, ?string $context = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sessions (id, base_ref, head_ref, context, diff_json) VALUES (?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            $id,
            $diff->baseRef,
            $diff->headRef,
            $context,
            json_encode($diff->toArray(), \JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * Check if a session has been submitted.
     */
    public function isSubmitted(string $sessionId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT status FROM sessions WHERE id = ?'
        );
        $stmt->execute([$sessionId]);
        $result = $stmt->fetch();

        return false !== $result && 'submitted' === $result['status'];
    }

    /**
     * Get the number of comments for a session.
     */
    public function commentCount(string $sessionId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) as count FROM comments WHERE session_id = ?'
        );
        $stmt->execute([$sessionId]);
        $result = $stmt->fetch();

        return (int) ($result['count'] ?? 0);
    }

    /**
     * Add a comment to a session.
     *
     * @return int The ID of the created comment
     */
    public function addComment(
        string $sessionId,
        string $filePath,
        int $startLine,
        int $endLine,
        string $body,
        string $side = 'new',
        string $tag = 'question',
        ?string $suggestion = null,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO comments (session_id, file_path, start_line, end_line, side, body, tag, suggestion)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            $sessionId,
            $filePath,
            $startLine,
            $endLine,
            $side,
            $body,
            $tag,
            $suggestion,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Update an existing comment.
     */
    public function updateComment(
        int $commentId,
        string $body,
        string $tag = 'question',
        ?string $suggestion = null,
        bool $resolved = false,
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE comments SET body = ?, tag = ?, suggestion = ?, resolved = ? WHERE id = ?'
        );

        $stmt->execute([
            $body,
            $tag,
            $suggestion,
            $resolved ? 1 : 0,
            $commentId,
        ]);
    }

    /**
     * Delete a comment.
     */
    public function deleteComment(int $commentId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM comments WHERE id = ?');
        $stmt->execute([$commentId]);
    }

    /**
     * Get all comments for a session.
     *
     * @return list<array{id: int, file_path: string, start_line: int, end_line: int, side: string, body: string, tag: string, suggestion: ?string, resolved: bool}>
     */
    public function getComments(string $sessionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, file_path, start_line, end_line, side, body, tag, suggestion, resolved
             FROM comments WHERE session_id = ? ORDER BY file_path, start_line'
        );
        $stmt->execute([$sessionId]);

        /* @var list<array{id: int, file_path: string, start_line: int, end_line: int, side: string, body: string, tag: string, suggestion: ?string, resolved: bool}> */
        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'file_path' => (string) $row['file_path'],
                'start_line' => (int) $row['start_line'],
                'end_line' => (int) $row['end_line'],
                'side' => (string) $row['side'],
                'body' => (string) $row['body'],
                'tag' => (string) $row['tag'],
                'suggestion' => null !== $row['suggestion'] ? (string) $row['suggestion'] : null,
                'resolved' => (bool) $row['resolved'],
            ],
            $stmt->fetchAll()
        );
    }

    /**
     * Submit the review with a verdict.
     */
    public function submitReview(string $sessionId, string $verdict, ?string $summaryNote = null): void
    {
        $this->pdo->beginTransaction();

        try {
            // Update session status
            $stmt = $this->pdo->prepare(
                'UPDATE sessions SET status = ?, submitted_at = datetime(\'now\') WHERE id = ?'
            );
            $stmt->execute(['submitted', $sessionId]);

            // Insert or replace verdict
            $stmt = $this->pdo->prepare(
                'INSERT OR REPLACE INTO review_summary (session_id, verdict, summary_note) VALUES (?, ?, ?)'
            );
            $stmt->execute([$sessionId, $verdict, $summaryNote]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Collect the complete review result for a session.
     */
    public function collectResult(string $sessionId): ?ReviewResult
    {
        // Get session data
        $stmt = $this->pdo->prepare(
            'SELECT id, base_ref, head_ref, status, diff_json FROM sessions WHERE id = ?'
        );
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch();

        if (false === $session) {
            return null;
        }

        // Get verdict
        $stmt = $this->pdo->prepare(
            'SELECT verdict, summary_note FROM review_summary WHERE session_id = ?'
        );
        $stmt->execute([$sessionId]);
        $summary = $stmt->fetch();

        // Get comments
        $comments = $this->getComments($sessionId);
        $reviewComments = array_map(
            static fn (array $c): ReviewComment => new ReviewComment(
                file: $c['file_path'],
                startLine: $c['start_line'],
                endLine: $c['end_line'],
                side: $c['side'],
                tag: $c['tag'],
                body: $c['body'],
                suggestion: $c['suggestion'],
                resolved: $c['resolved'],
            ),
            $comments
        );

        // Calculate meta
        $diffData = json_decode((string) $session['diff_json'], true, 512, \JSON_THROW_ON_ERROR);
        $filesReviewed = \count($diffData['files'] ?? []);

        $byTag = [];
        foreach ($reviewComments as $comment) {
            $tag = $comment->tag;
            $byTag[$tag] = ($byTag[$tag] ?? 0) + 1;
        }

        return new ReviewResult(
            sessionId: $session['id'],
            status: $session['status'],
            verdict: false !== $summary ? $summary['verdict'] : null,
            summary: false !== $summary ? $summary['summary_note'] : null,
            comments: $reviewComments,
            meta: [
                'baseRef' => $session['base_ref'],
                'headRef' => $session['head_ref'],
                'filesReviewed' => $filesReviewed,
                'commentCount' => \count($reviewComments),
                'byTag' => $byTag,
            ],
        );
    }

    /**
     * Get the diff JSON for a session.
     */
    public function getDiffJson(string $sessionId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT diff_json FROM sessions WHERE id = ?');
        $stmt->execute([$sessionId]);
        $result = $stmt->fetch();

        return false !== $result ? $result['diff_json'] : null;
    }

    /**
     * Get the context for a session.
     */
    public function getContext(string $sessionId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT context FROM sessions WHERE id = ?');
        $stmt->execute([$sessionId]);
        $result = $stmt->fetch();

        return false !== $result ? $result['context'] : null;
    }

    /**
     * Check if a session exists.
     */
    public function sessionExists(string $sessionId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM sessions WHERE id = ?');
        $stmt->execute([$sessionId]);

        return false !== $stmt->fetch();
    }

    /**
     * Add a chat message.
     *
     * @return int The ID of the created message
     */
    public function addChatMessage(
        string $sessionId,
        string $role,
        string $content,
        ?string $fileContext = null,
        ?int $lineContext = null,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO chat_messages (session_id, role, content, file_context, line_context)
             VALUES (?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            $sessionId,
            $role,
            $content,
            $fileContext,
            $lineContext,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Get pending questions (user messages without answers).
     *
     * @return list<array{id: int, content: string, file_context: ?string, line_context: ?int, status: string}>
     */
    public function getPendingQuestions(string $sessionId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, content, file_context, line_context, status
             FROM chat_messages
             WHERE session_id = ? AND role = 'user' AND status = 'pending'
             ORDER BY id ASC"
        );
        $stmt->execute([$sessionId]);

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'content' => (string) $row['content'],
                'file_context' => null !== $row['file_context'] ? (string) $row['file_context'] : null,
                'line_context' => null !== $row['line_context'] ? (int) $row['line_context'] : null,
                'status' => (string) $row['status'],
            ],
            $stmt->fetchAll()
        );
    }

    /**
     * Get all chat messages for a session.
     *
     * @return list<array{id: int, role: string, content: string, file_context: ?string, line_context: ?int, parent_id: ?int, status: string, error_message: ?string, created_at: string}>
     */
    public function getChatMessages(string $sessionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, role, content, file_context, line_context, parent_id, status, error_message, created_at
             FROM chat_messages
             WHERE session_id = ?
             ORDER BY id ASC'
        );
        $stmt->execute([$sessionId]);

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'role' => (string) $row['role'],
                'content' => (string) $row['content'],
                'file_context' => null !== $row['file_context'] ? (string) $row['file_context'] : null,
                'line_context' => null !== $row['line_context'] ? (int) $row['line_context'] : null,
                'parent_id' => null !== $row['parent_id'] ? (int) $row['parent_id'] : null,
                'status' => (string) $row['status'],
                'error_message' => null !== $row['error_message'] ? (string) $row['error_message'] : null,
                'created_at' => (string) $row['created_at'],
            ],
            $stmt->fetchAll()
        );
    }

    /**
     * Update chat message status.
     */
    public function updateChatMessageStatus(int $messageId, string $status, ?string $errorMessage = null): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE chat_messages SET status = ?, error_message = ? WHERE id = ?'
        );

        $stmt->execute([$status, $errorMessage, $messageId]);
    }

    /**
     * Add an answer to a chat question.
     *
     * @return int The ID of the created answer message
     */
    public function addChatAnswer(string $sessionId, int $questionId, string $content): int
    {
        $this->pdo->beginTransaction();

        try {
            // Update question status to answered
            $this->updateChatMessageStatus($questionId, 'answered');

            // Add answer message
            $stmt = $this->pdo->prepare(
                'INSERT INTO chat_messages (session_id, role, content, parent_id, status)
                 VALUES (?, ?, ?, ?, ?)'
            );

            $stmt->execute([
                $sessionId,
                'assistant',
                $content,
                $questionId,
                'answered',
            ]);

            $answerId = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();

            return $answerId;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Check if there are pending chat questions.
     */
    public function hasPendingQuestions(string $sessionId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM chat_messages
             WHERE session_id = ? AND role = 'user' AND status = 'pending'
             LIMIT 1"
        );
        $stmt->execute([$sessionId]);

        return false !== $stmt->fetch();
    }

    /**
     * Initialize the database schema.
     */
    private function initializeSchema(): void
    {
        $schemaPath = __DIR__.'/schema.sql';

        if (!file_exists($schemaPath)) {
            throw new \RuntimeException('Schema file not found: '.$schemaPath);
        }

        $schema = file_get_contents($schemaPath);

        if (false === $schema) {
            throw new \RuntimeException('Failed to read schema file');
        }

        $this->pdo->exec($schema);
    }
}
