<?php

/*
 * This file is part of the MatesOfMate Organisation.
 *
 * (c) Johannes Wachter <johannes@sulu.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace MatesOfMate\SelfReviewExtension\Formatter;

use MatesOfMate\SelfReviewExtension\Output\ReviewResult;
use MatesOfMate\SelfReviewExtension\Server\ReviewSession;

/**
 * Formats review output using TOON (Token-Oriented Object Notation) for token-efficient output.
 *
 * @internal
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
class ToonFormatter
{
    /**
     * Format the start response when a review session is created.
     */
    public function formatStartResponse(ReviewSession $session): string
    {
        return toon([
            'status' => 'started',
            'session' => [
                'id' => $session->getId(),
                'url' => $session->getUrl(),
                'files' => $session->getFileCount(),
            ],
            'instructions' => 'Review opened in browser. Call self-review-result with session_id when ready.',
        ]);
    }

    /**
     * Format the complete review result.
     */
    public function formatResult(ReviewResult $result): string
    {
        $data = [
            'status' => $result->status,
            'session_id' => $result->sessionId,
        ];

        if ($result->isSubmitted()) {
            $data['verdict'] = $result->verdict;

            if (null !== $result->summary) {
                $data['summary'] = $result->summary;
            }

            $data['stats'] = [
                'files' => $result->meta['filesReviewed'] ?? 0,
                'comments' => $result->getCommentCount(),
                'by_tag' => $result->meta['byTag'] ?? [],
            ];

            if ($result->hasBlockingComments()) {
                $data['has_blockers'] = true;
            }

            if ([] !== $result->comments) {
                $data['comments'] = $this->formatComments($result);
            }
        }

        return toon($data);
    }

    /**
     * Format the waiting response when review is not yet submitted.
     */
    public function formatWaiting(ReviewSession $session): string
    {
        return toon([
            'status' => 'waiting',
            'session_id' => $session->getId(),
            'url' => $session->getUrl(),
            'comment_count' => $session->commentCount(),
            'message' => 'Review not yet submitted. Try again later or continue working.',
        ]);
    }

    /**
     * Format error response.
     */
    public function formatError(string $message, ?string $sessionId = null): string
    {
        $data = [
            'status' => 'error',
            'error' => $message,
        ];

        if (null !== $sessionId) {
            $data['session_id'] = $sessionId;
        }

        return toon($data);
    }

    /**
     * Format the chat processing result.
     */
    public function formatChatResult(int $answered, int $total): string
    {
        return toon([
            'chat_processed' => true,
            'questions_answered' => $answered,
            'questions_total' => $total,
            'questions_failed' => $total - $answered,
        ]);
    }

    /**
     * Format message when no pending questions exist.
     */
    public function formatNoPendingQuestions(string $sessionId): string
    {
        return toon([
            'status' => 'no_pending_questions',
            'session_id' => $sessionId,
            'message' => 'No pending chat questions to answer.',
        ]);
    }

    /**
     * Format comments grouped by file for token efficiency.
     *
     * @return array<string, list<array{lines: string, tag: string, body: string, suggestion?: string}>>
     */
    private function formatComments(ReviewResult $result): array
    {
        $grouped = [];

        foreach ($result->comments as $comment) {
            $file = $comment->file;

            if (!isset($grouped[$file])) {
                $grouped[$file] = [];
            }

            $commentData = [
                'lines' => $comment->startLine === $comment->endLine
                    ? (string) $comment->startLine
                    : \sprintf('%d-%d', $comment->startLine, $comment->endLine),
                'tag' => $comment->tag,
                'body' => $comment->body,
            ];

            if (null !== $comment->suggestion) {
                $commentData['suggestion'] = $comment->suggestion;
            }

            $grouped[$file][] = $commentData;
        }

        return $grouped;
    }
}
