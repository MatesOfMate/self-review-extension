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
 * Value object representing a single review comment.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final readonly class ReviewComment
{
    public const TAG_QUESTION = 'question';
    public const TAG_ISSUE = 'issue';
    public const TAG_SUGGESTION = 'suggestion';
    public const TAG_PRAISE = 'praise';
    public const TAG_NITPICK = 'nitpick';
    public const TAG_BLOCKER = 'blocker';

    public function __construct(
        public string $file,
        public int $startLine,
        public int $endLine,
        public string $side,
        public string $tag,
        public string $body,
        public ?string $suggestion = null,
        public bool $resolved = false,
    ) {
    }

    /**
     * @return array{file: string, lines: string, side: string, tag: string, body: string, suggestion?: string, resolved: bool}
     */
    public function toArray(): array
    {
        $data = [
            'file' => $this->file,
            'lines' => $this->startLine === $this->endLine
                ? (string) $this->startLine
                : \sprintf('%d-%d', $this->startLine, $this->endLine),
            'side' => $this->side,
            'tag' => $this->tag,
            'body' => $this->body,
            'resolved' => $this->resolved,
        ];

        if (null !== $this->suggestion) {
            $data['suggestion'] = $this->suggestion;
        }

        return $data;
    }

    /**
     * Check if this is a blocking comment.
     */
    public function isBlocking(): bool
    {
        return self::TAG_BLOCKER === $this->tag || self::TAG_ISSUE === $this->tag;
    }
}
