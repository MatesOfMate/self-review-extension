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
            'next_action' => 'IMMEDIATELY call self-review-result with this session_id to wait for review completion.',
            'instructions' => 'Review opened in browser. The human will review and may ask questions via chat.',
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
            'message' => 'Review not yet submitted. Timeout reached, call self-review-result again to continue waiting.',
        ]);
    }

    /**
     * Format waiting response with pending questions that need answers.
     *
     * @param array<array{id: int, content: string, file_context: ?string, line_context: ?int, status: string}> $questions
     */
    public function formatWaitingWithQuestions(ReviewSession $session, array $questions): string
    {
        $formattedQuestions = [];

        foreach ($questions as $question) {
            $q = [
                'id' => $question['id'],
                'question' => $question['content'],
            ];

            if (null !== $question['file_context']) {
                $q['file'] = $question['file_context'];
            }

            if (null !== $question['line_context']) {
                $q['line'] = $question['line_context'];
            }

            $formattedQuestions[] = $q;
        }

        return toon([
            'status' => 'waiting',
            'session_id' => $session->getId(),
            'url' => $session->getUrl(),
            'comment_count' => $session->commentCount(),
            'pending_questions' => $formattedQuestions,
            'instructions' => 'Answer questions using self-review-answer, then call self-review-result again.',
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
     * Format pending questions for the agent to answer.
     *
     * @param array<array{id: int, content: string, file_context: ?string, line_context: ?int, status: string}> $questions
     */
    public function formatPendingQuestions(array $questions, ?string $context): string
    {
        $formattedQuestions = [];

        foreach ($questions as $question) {
            $q = [
                'id' => $question['id'],
                'question' => $question['content'],
            ];

            if (null !== $question['file_context']) {
                $q['file'] = $question['file_context'];
            }

            if (null !== $question['line_context']) {
                $q['line'] = $question['line_context'];
            }

            $formattedQuestions[] = $q;
        }

        $data = [
            'status' => 'pending_questions',
            'count' => \count($questions),
            'questions' => $formattedQuestions,
            'instructions' => 'Answer each question using self-review-answer with the question id.',
        ];

        if (null !== $context) {
            $data['context'] = $context;
        }

        return toon($data);
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
     * Format confirmation that an answer was submitted.
     */
    public function formatAnswerSubmitted(int $questionId): string
    {
        return toon([
            'status' => 'answer_submitted',
            'question_id' => $questionId,
            'message' => 'Answer submitted successfully. The reviewer will see it in the chat.',
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
