<?php

/*
 * This file is part of the MatesOfMate Organisation.
 *
 * (c) Johannes Wachter <johannes@sulu.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace MatesOfMate\SelfReviewExtension\Tests\Unit\Capability;

use MatesOfMate\SelfReviewExtension\Capability\SelfReviewTool;
use MatesOfMate\SelfReviewExtension\Formatter\ToonFormatter;
use MatesOfMate\SelfReviewExtension\Git\DiffResult;
use MatesOfMate\SelfReviewExtension\Git\GitDiffResolver;
use MatesOfMate\SelfReviewExtension\Server\ReviewSessionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
class SelfReviewToolTest extends TestCase
{
    private GitDiffResolver&MockObject $diffResolver;

    private ReviewSessionFactory&MockObject $sessionFactory;

    private ToonFormatter $formatter;

    private SelfReviewTool $tool;

    protected function setUp(): void
    {
        $this->diffResolver = $this->createMock(GitDiffResolver::class);
        $this->sessionFactory = $this->createMock(ReviewSessionFactory::class);
        $this->formatter = new ToonFormatter();

        $this->tool = new SelfReviewTool(
            $this->diffResolver,
            $this->sessionFactory,
            $this->formatter
        );
    }

    public function testStartReturnsErrorWhenBaseRefNotExists(): void
    {
        $this->diffResolver
            ->method('refExists')
            ->willReturnCallback(static fn (string $ref): bool => 'HEAD' === $ref);

        $result = $this->tool->start(base_ref: 'nonexistent');

        $this->assertStringContainsString('error', $result);
        $this->assertStringContainsString('nonexistent', $result);
        $this->assertStringContainsString('does not exist', $result);
    }

    public function testStartReturnsErrorWhenHeadRefNotExists(): void
    {
        $this->diffResolver
            ->method('refExists')
            ->willReturnCallback(static fn (string $ref): bool => 'main' === $ref);

        $result = $this->tool->start(base_ref: 'main', head_ref: 'nonexistent');

        $this->assertStringContainsString('error', $result);
        $this->assertStringContainsString('nonexistent', $result);
    }

    public function testStartReturnsErrorWhenNoDiffFound(): void
    {
        $this->diffResolver
            ->method('refExists')
            ->willReturn(true);

        $emptyDiff = new DiffResult('main', 'HEAD', []);

        $this->diffResolver
            ->method('resolve')
            ->willReturn($emptyDiff);

        $result = $this->tool->start();

        $this->assertStringContainsString('error', $result);
        $this->assertStringContainsString('No changes found', $result);
    }

    public function testResultReturnsErrorForUnknownSession(): void
    {
        $result = $this->tool->result('unknown-session');

        $this->assertStringContainsString('error', $result);
        $this->assertStringContainsString('Session not found', $result);
    }
}
