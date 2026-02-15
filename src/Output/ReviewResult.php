<?php

/*
 * This file is part of the MatesOfMate Organisation.
 *
 * (c) Johannes Wachter <johannes@sulu.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace MatesOfMate\SelfReviewExtension\Output;

/**
 * Value object representing a complete review result.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final readonly class ReviewResult
{
    public const VERDICT_APPROVED = 'approved';
    public const VERDICT_CHANGES_REQUESTED = 'changes_requested';
    public const VERDICT_COMMENT = 'comment';

    /**
     * @param string               $sessionId Session identifier
     * @param string               $status    Review status (in_progress, submitted)
     * @param string|null          $verdict   Verdict (approved, changes_requested, comment)
     * @param string|null          $summary   Summary note from reviewer
     * @param list<ReviewComment>  $comments  List of review comments
     * @param array<string, mixed> $meta      Metadata (baseRef, headRef, filesReviewed, byTag counts)
     */
    public function __construct(
        public string $sessionId,
        public string $status,
        public ?string $verdict,
        public ?string $summary,
        public array $comments,
        public array $meta,
    ) {
    }

    public function isSubmitted(): bool
    {
        return 'submitted' === $this->status;
    }

    public function isApproved(): bool
    {
        return self::VERDICT_APPROVED === $this->verdict;
    }

    public function hasBlockingComments(): bool
    {
        foreach ($this->comments as $comment) {
            if ($comment->isBlocking() && !$comment->resolved) {
                return true;
            }
        }

        return false;
    }

    public function getCommentCount(): int
    {
        return \count($this->comments);
    }

    /**
     * @return array{sessionId: string, status: string, verdict: ?string, summary: ?string, comments: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'sessionId' => $this->sessionId,
            'status' => $this->status,
            'verdict' => $this->verdict,
            'summary' => $this->summary,
            'comments' => array_map(
                static fn (ReviewComment $c): array => $c->toArray(),
                $this->comments
            ),
            'meta' => $this->meta,
        ];
    }
}
