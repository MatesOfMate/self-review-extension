<?php

/*
 * This file is part of the MatesOfMate Organisation.
 *
 * (c) Johannes Wachter <johannes@sulu.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace MatesOfMate\SelfReviewExtension\Tests\Unit\Storage;

use MatesOfMate\SelfReviewExtension\Git\ChangedFile;
use MatesOfMate\SelfReviewExtension\Git\DiffHunk;
use MatesOfMate\SelfReviewExtension\Git\DiffResult;
use MatesOfMate\SelfReviewExtension\Storage\Database;
use PHPUnit\Framework\TestCase;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
class DatabaseTest extends TestCase
{
    private string $dbPath;

    private Database $database;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir().'/self-review-test-'.bin2hex(random_bytes(4)).'.sqlite';
        $this->database = new Database($this->dbPath);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testCreateSession(): void
    {
        $diff = $this->createDiffResult();

        $this->database->createSession('test-session', $diff, 'Test context');

        $this->assertTrue($this->database->sessionExists('test-session'));
        $this->assertSame('Test context', $this->database->getContext('test-session'));
    }

    public function testSessionNotExists(): void
    {
        $this->assertFalse($this->database->sessionExists('nonexistent'));
    }

    public function testIsSubmittedInitiallyFalse(): void
    {
        $diff = $this->createDiffResult();
        $this->database->createSession('test-session', $diff);

        $this->assertFalse($this->database->isSubmitted('test-session'));
    }

    public function testCommentCountInitiallyZero(): void
    {
        $diff = $this->createDiffResult();
        $this->database->createSession('test-session', $diff);

        $this->assertSame(0, $this->database->commentCount('test-session'));
    }

    public function testAddComment(): void
    {
        $diff = $this->createDiffResult();
        $this->database->createSession('test-session', $diff);

        $id = $this->database->addComment(
            sessionId: 'test-session',
            filePath: 'src/Example.php',
            startLine: 10,
            endLine: 15,
            body: 'This looks good!',
            side: 'new',
            tag: 'praise',
        );

        $this->assertGreaterThan(0, $id);
        $this->assertSame(1, $this->database->commentCount('test-session'));
    }

    public function testAddCommentWithSuggestion(): void
    {
        $diff = $this->createDiffResult();
        $this->database->createSession('test-session', $diff);

        $this->database->addComment(
            sessionId: 'test-session',
            filePath: 'src/Example.php',
            startLine: 10,
            endLine: 10,
            body: 'Consider using a constant here',
            tag: 'suggestion',
            suggestion: 'const MAX_VALUE = 100;',
        );

        $comments = $this->database->getComments('test-session');
        $this->assertCount(1, $comments);
        $this->assertSame('const MAX_VALUE = 100;', $comments[0]['suggestion']);
    }

    public function testGetComments(): void
    {
        $diff = $this->createDiffResult();
        $this->database->createSession('test-session', $diff);

        $this->database->addComment(
            sessionId: 'test-session',
            filePath: 'src/A.php',
            startLine: 5,
            endLine: 5,
            body: 'First comment',
            tag: 'question',
        );

        $this->database->addComment(
            sessionId: 'test-session',
            filePath: 'src/B.php',
            startLine: 10,
            endLine: 12,
            body: 'Second comment',
            tag: 'issue',
        );

        $comments = $this->database->getComments('test-session');

        $this->assertCount(2, $comments);
        $this->assertSame('src/A.php', $comments[0]['file_path']);
        $this->assertSame('src/B.php', $comments[1]['file_path']);
        $this->assertSame('question', $comments[0]['tag']);
        $this->assertSame('issue', $comments[1]['tag']);
    }

    public function testUpdateComment(): void
    {
        $diff = $this->createDiffResult();
        $this->database->createSession('test-session', $diff);

        $id = $this->database->addComment(
            sessionId: 'test-session',
            filePath: 'src/Example.php',
            startLine: 10,
            endLine: 10,
            body: 'Original body',
            tag: 'question',
        );

        $this->database->updateComment(
            commentId: $id,
            body: 'Updated body',
            tag: 'suggestion',
            suggestion: 'New code here',
            resolved: true,
        );

        $comments = $this->database->getComments('test-session');
        $this->assertSame('Updated body', $comments[0]['body']);
        $this->assertSame('suggestion', $comments[0]['tag']);
        $this->assertSame('New code here', $comments[0]['suggestion']);
        $this->assertTrue($comments[0]['resolved']);
    }

    public function testDeleteComment(): void
    {
        $diff = $this->createDiffResult();
        $this->database->createSession('test-session', $diff);

        $id = $this->database->addComment(
            sessionId: 'test-session',
            filePath: 'src/Example.php',
            startLine: 10,
            endLine: 10,
            body: 'To be deleted',
            tag: 'question',
        );

        $this->assertSame(1, $this->database->commentCount('test-session'));

        $this->database->deleteComment($id);

        $this->assertSame(0, $this->database->commentCount('test-session'));
    }

    public function testSubmitReview(): void
    {
        $diff = $this->createDiffResult();
        $this->database->createSession('test-session', $diff);

        $this->database->submitReview('test-session', 'approved', 'Looks good to me!');

        $this->assertTrue($this->database->isSubmitted('test-session'));
    }

    public function testCollectResult(): void
    {
        $diff = $this->createDiffResult();
        $this->database->createSession('test-session', $diff, 'Review context');

        $this->database->addComment(
            sessionId: 'test-session',
            filePath: 'src/Example.php',
            startLine: 10,
            endLine: 10,
            body: 'Nice work!',
            tag: 'praise',
        );

        $this->database->addComment(
            sessionId: 'test-session',
            filePath: 'src/Example.php',
            startLine: 20,
            endLine: 25,
            body: 'This could be improved',
            tag: 'suggestion',
            suggestion: 'Better code here',
        );

        $this->database->submitReview('test-session', 'changes_requested', 'Please address the suggestion');

        $result = $this->database->collectResult('test-session');

        $this->assertNotNull($result);
        $this->assertSame('test-session', $result->sessionId);
        $this->assertSame('submitted', $result->status);
        $this->assertSame('changes_requested', $result->verdict);
        $this->assertSame('Please address the suggestion', $result->summary);
        $this->assertCount(2, $result->comments);
        $this->assertSame(1, $result->meta['filesReviewed']);
        $this->assertSame(['praise' => 1, 'suggestion' => 1], $result->meta['byTag']);
    }

    public function testCollectResultReturnsNullForNonexistentSession(): void
    {
        $result = $this->database->collectResult('nonexistent');

        $this->assertNull($result);
    }

    public function testGetDiffJson(): void
    {
        $diff = $this->createDiffResult();
        $this->database->createSession('test-session', $diff);

        $diffJson = $this->database->getDiffJson('test-session');

        $this->assertNotNull($diffJson);
        $decoded = json_decode($diffJson, true);
        $this->assertSame('main', $decoded['baseRef']);
        $this->assertSame('HEAD', $decoded['headRef']);
    }

    public function testAddChatMessage(): void
    {
        $diff = $this->createDiffResult();
        $this->database->createSession('test-session', $diff);

        $id = $this->database->addChatMessage(
            sessionId: 'test-session',
            role: 'user',
            content: 'Why did you change this?',
            fileContext: 'src/Example.php',
            lineContext: 42,
        );

        $this->assertGreaterThan(0, $id);
    }

    public function testGetPendingQuestions(): void
    {
        $diff = $this->createDiffResult();
        $this->database->createSession('test-session', $diff);

        $this->database->addChatMessage(
            sessionId: 'test-session',
            role: 'user',
            content: 'First question',
            fileContext: 'src/A.php',
        );

        $this->database->addChatMessage(
            sessionId: 'test-session',
            role: 'user',
            content: 'Second question',
        );

        $questions = $this->database->getPendingQuestions('test-session');

        $this->assertCount(2, $questions);
        $this->assertSame('First question', $questions[0]['content']);
        $this->assertSame('src/A.php', $questions[0]['file_context']);
        $this->assertSame('pending', $questions[0]['status']);
        $this->assertSame('Second question', $questions[1]['content']);
    }

    public function testGetChatMessages(): void
    {
        $diff = $this->createDiffResult();
        $this->database->createSession('test-session', $diff);

        $questionId = $this->database->addChatMessage(
            sessionId: 'test-session',
            role: 'user',
            content: 'Why this approach?',
        );

        $this->database->addChatAnswer(
            sessionId: 'test-session',
            questionId: $questionId,
            content: 'Because it is more efficient.',
        );

        $messages = $this->database->getChatMessages('test-session');

        $this->assertCount(2, $messages);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertSame('Why this approach?', $messages[0]['content']);
        $this->assertSame('answered', $messages[0]['status']);
        $this->assertSame('assistant', $messages[1]['role']);
        $this->assertSame('Because it is more efficient.', $messages[1]['content']);
        $this->assertSame($questionId, $messages[1]['parent_id']);
    }

    public function testUpdateChatMessageStatus(): void
    {
        $diff = $this->createDiffResult();
        $this->database->createSession('test-session', $diff);

        $id = $this->database->addChatMessage(
            sessionId: 'test-session',
            role: 'user',
            content: 'Question',
        );

        $this->database->updateChatMessageStatus($id, 'processing');

        $questions = $this->database->getPendingQuestions('test-session');
        $this->assertCount(0, $questions); // No longer pending

        $messages = $this->database->getChatMessages('test-session');
        $this->assertSame('processing', $messages[0]['status']);
    }

    public function testUpdateChatMessageStatusWithError(): void
    {
        $diff = $this->createDiffResult();
        $this->database->createSession('test-session', $diff);

        $id = $this->database->addChatMessage(
            sessionId: 'test-session',
            role: 'user',
            content: 'Question',
        );

        $this->database->updateChatMessageStatus($id, 'error', 'Sampling failed');

        $messages = $this->database->getChatMessages('test-session');
        $this->assertSame('error', $messages[0]['status']);
        $this->assertSame('Sampling failed', $messages[0]['error_message']);
    }

    public function testHasPendingQuestions(): void
    {
        $diff = $this->createDiffResult();
        $this->database->createSession('test-session', $diff);

        $this->assertFalse($this->database->hasPendingQuestions('test-session'));

        $this->database->addChatMessage(
            sessionId: 'test-session',
            role: 'user',
            content: 'Question',
        );

        $this->assertTrue($this->database->hasPendingQuestions('test-session'));
    }

    public function testAddChatAnswerUpdatesQuestionStatus(): void
    {
        $diff = $this->createDiffResult();
        $this->database->createSession('test-session', $diff);

        $questionId = $this->database->addChatMessage(
            sessionId: 'test-session',
            role: 'user',
            content: 'Question',
        );

        $this->assertTrue($this->database->hasPendingQuestions('test-session'));

        $answerId = $this->database->addChatAnswer(
            sessionId: 'test-session',
            questionId: $questionId,
            content: 'Answer',
        );

        $this->assertFalse($this->database->hasPendingQuestions('test-session'));
        $this->assertGreaterThan($questionId, $answerId);
    }

    private function createDiffResult(): DiffResult
    {
        $hunk = new DiffHunk(
            oldStart: 1,
            oldCount: 5,
            newStart: 1,
            newCount: 6,
            lines: [
                ['type' => 'context', 'content' => '<?php', 'oldLine' => 1, 'newLine' => 1],
                ['type' => 'remove', 'content' => '// old', 'oldLine' => 2, 'newLine' => null],
                ['type' => 'add', 'content' => '// new', 'oldLine' => null, 'newLine' => 2],
            ]
        );

        $file = new ChangedFile(
            path: 'src/Example.php',
            status: ChangedFile::STATUS_MODIFIED,
            hunks: [$hunk],
        );

        return new DiffResult(
            baseRef: 'main',
            headRef: 'HEAD',
            files: [$file],
        );
    }
}
