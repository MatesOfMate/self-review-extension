<?php

/*
 * This file is part of the MatesOfMate Organisation.
 *
 * (c) Johannes Wachter <johannes@sulu.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace MatesOfMate\SelfReviewExtension\Tests\Unit\Formatter;

use MatesOfMate\SelfReviewExtension\Formatter\ToonFormatter;
use MatesOfMate\SelfReviewExtension\Output\ReviewComment;
use MatesOfMate\SelfReviewExtension\Output\ReviewResult;
use PHPUnit\Framework\TestCase;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
class ToonFormatterTest extends TestCase
{
    private ToonFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new ToonFormatter();
    }

    public function testFormatResultSubmitted(): void
    {
        $result = new ReviewResult(
            sessionId: 'abc123',
            status: 'submitted',
            verdict: 'approved',
            summary: 'Looks good!',
            comments: [
                new ReviewComment(
                    file: 'src/Example.php',
                    startLine: 10,
                    endLine: 10,
                    side: 'new',
                    tag: 'praise',
                    body: 'Nice implementation',
                ),
            ],
            meta: [
                'baseRef' => 'main',
                'headRef' => 'HEAD',
                'filesReviewed' => 3,
                'byTag' => ['praise' => 1],
            ],
        );

        $output = $this->formatter->formatResult($result);

        // TOON output should contain key information
        $this->assertStringContainsString('submitted', $output);
        $this->assertStringContainsString('abc123', $output);
        $this->assertStringContainsString('approved', $output);
        $this->assertStringContainsString('Looks good', $output);
        $this->assertStringContainsString('src/Example.php', $output);
    }

    public function testFormatResultInProgress(): void
    {
        $result = new ReviewResult(
            sessionId: 'abc123',
            status: 'in_progress',
            verdict: null,
            summary: null,
            comments: [],
            meta: [],
        );

        $output = $this->formatter->formatResult($result);

        $this->assertStringContainsString('in_progress', $output);
        $this->assertStringContainsString('abc123', $output);
    }

    public function testFormatResultGroupsCommentsByFile(): void
    {
        $result = new ReviewResult(
            sessionId: 'abc123',
            status: 'submitted',
            verdict: 'changes_requested',
            summary: null,
            comments: [
                new ReviewComment(
                    file: 'src/FileA.php',
                    startLine: 5,
                    endLine: 5,
                    side: 'new',
                    tag: 'issue',
                    body: 'Bug here',
                ),
                new ReviewComment(
                    file: 'src/FileA.php',
                    startLine: 20,
                    endLine: 22,
                    side: 'new',
                    tag: 'suggestion',
                    body: 'Consider refactoring',
                    suggestion: 'Better code',
                ),
                new ReviewComment(
                    file: 'src/FileB.php',
                    startLine: 10,
                    endLine: 10,
                    side: 'new',
                    tag: 'question',
                    body: 'Why this approach?',
                ),
            ],
            meta: [
                'filesReviewed' => 2,
                'byTag' => ['issue' => 1, 'suggestion' => 1, 'question' => 1],
            ],
        );

        $output = $this->formatter->formatResult($result);

        $this->assertStringContainsString('src/FileA.php', $output);
        $this->assertStringContainsString('src/FileB.php', $output);
        $this->assertStringContainsString('Bug here', $output);
        $this->assertStringContainsString('Consider refactoring', $output);
        $this->assertStringContainsString('Better code', $output);
    }

    public function testFormatResultWithBlockingComments(): void
    {
        $result = new ReviewResult(
            sessionId: 'abc123',
            status: 'submitted',
            verdict: 'changes_requested',
            summary: null,
            comments: [
                new ReviewComment(
                    file: 'src/Security.php',
                    startLine: 15,
                    endLine: 15,
                    side: 'new',
                    tag: 'blocker',
                    body: 'Security vulnerability!',
                ),
            ],
            meta: [
                'filesReviewed' => 1,
                'byTag' => ['blocker' => 1],
            ],
        );

        $output = $this->formatter->formatResult($result);

        $this->assertStringContainsString('has_blockers', $output);
        $this->assertStringContainsString('blocker', $output);
    }

    public function testFormatError(): void
    {
        $output = $this->formatter->formatError('Something went wrong', 'abc123');

        $this->assertStringContainsString('error', $output);
        $this->assertStringContainsString('Something went wrong', $output);
        $this->assertStringContainsString('abc123', $output);
    }

    public function testFormatErrorWithoutSessionId(): void
    {
        $output = $this->formatter->formatError('Connection failed');

        $this->assertStringContainsString('error', $output);
        $this->assertStringContainsString('Connection failed', $output);
    }

    public function testFormatChatResult(): void
    {
        $output = $this->formatter->formatChatResult(3, 4);

        $this->assertStringContainsString('chat_processed', $output);
        $this->assertStringContainsString('questions_answered', $output);
        $this->assertStringContainsString('3', $output);
        $this->assertStringContainsString('questions_total', $output);
        $this->assertStringContainsString('4', $output);
        $this->assertStringContainsString('questions_failed', $output);
        $this->assertStringContainsString('1', $output);
    }

    public function testFormatChatResultAllAnswered(): void
    {
        $output = $this->formatter->formatChatResult(5, 5);

        $this->assertStringContainsString('chat_processed', $output);
        $this->assertStringContainsString('questions_answered', $output);
        $this->assertStringContainsString('5', $output);
    }

    public function testFormatNoPendingQuestions(): void
    {
        $output = $this->formatter->formatNoPendingQuestions('abc123');

        $this->assertStringContainsString('no_pending_questions', $output);
        $this->assertStringContainsString('abc123', $output);
        $this->assertStringContainsString('No pending chat questions', $output);
    }
}
