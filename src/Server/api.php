<?php

/*
 * This file is part of the MatesOfMate Organisation.
 *
 * (c) Johannes Wachter <johannes@sulu.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * API endpoints for the review interface.
 * This is a standalone script for the PHP built-in server.
 */

// Load autoloader
$autoloadPaths = [
    __DIR__.'/../../vendor/autoload.php',
    __DIR__.'/../../../autoload.php',
    __DIR__.'/../../../../autoload.php',
    __DIR__.'/../../../../../autoload.php',
    __DIR__.'/../../../../../../autoload.php',
];

$autoloaded = false;
foreach ($autoloadPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $autoloaded = true;
        break;
    }
}

if (!$autoloaded) {
    http_response_code(500);
    echo json_encode(['error' => 'Autoloader not found']);
    exit;
}

use MatesOfMate\SelfReviewExtension\Storage\Database;

// Get session info from environment (use getenv for PHP built-in server)
$sessionId = getenv('SELF_REVIEW_SESSION_ID') ?: ($_SERVER['SELF_REVIEW_SESSION_ID'] ?? null);
$dbPath = getenv('SELF_REVIEW_DB_PATH') ?: ($_SERVER['SELF_REVIEW_DB_PATH'] ?? null);

if (null === $sessionId || null === $dbPath) {
    http_response_code(500);
    echo json_encode(['error' => 'Session not configured']);
    exit;
}

// Initialize database
try {
    $db = new Database($dbPath);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: '.$e->getMessage()]);
    exit;
}

// Parse request
$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];
$path = parse_url((string) $uri, \PHP_URL_PATH);
$path = str_replace('/api', '', (string) $path);

// Set JSON headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle CORS preflight
if ('OPTIONS' === $method) {
    http_response_code(204);
    exit;
}

// Get request body for POST/PUT
$body = null;
if (in_array($method, ['POST', 'PUT'], true)) {
    $rawBody = file_get_contents('php://input');
    if (false !== $rawBody && '' !== $rawBody) {
        $body = json_decode($rawBody, true);
    }
}

// Route handlers
try {
    switch (true) {
        // GET /api/diff - Get parsed diff
        case 'GET' === $method && '/diff' === $path:
            $diffJson = $db->getDiffJson($sessionId);
            if (null === $diffJson) {
                http_response_code(404);
                echo json_encode(['error' => 'Diff not found']);
            } else {
                echo $diffJson;
            }
            break;

            // GET /api/context - Get agent's context message
        case 'GET' === $method && '/context' === $path:
            $context = $db->getContext($sessionId);
            echo json_encode(['context' => $context]);
            break;

            // GET /api/comments - Get all comments
        case 'GET' === $method && '/comments' === $path:
            $comments = $db->getComments($sessionId);
            echo json_encode(['comments' => $comments]);
            break;

            // POST /api/comments - Create a comment
        case 'POST' === $method && '/comments' === $path:
            if (null === $body) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid request body']);
                break;
            }

            $id = $db->addComment(
                sessionId: $sessionId,
                filePath: $body['file_path'] ?? '',
                startLine: (int) ($body['start_line'] ?? 1),
                endLine: (int) ($body['end_line'] ?? $body['start_line'] ?? 1),
                body: $body['body'] ?? '',
                side: $body['side'] ?? 'new',
                tag: $body['tag'] ?? 'question',
                suggestion: $body['suggestion'] ?? null,
            );

            echo json_encode(['id' => $id, 'success' => true]);
            break;

            // PUT /api/comments/{id} - Update a comment
        case 'PUT' === $method && preg_match('#^/comments/(\d+)$#', $path, $matches):
            $commentId = (int) $matches[1];

            if (null === $body) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid request body']);
                break;
            }

            $db->updateComment(
                commentId: $commentId,
                body: $body['body'] ?? '',
                tag: $body['tag'] ?? 'question',
                suggestion: $body['suggestion'] ?? null,
                resolved: (bool) ($body['resolved'] ?? false),
            );

            echo json_encode(['success' => true]);
            break;

            // DELETE /api/comments/{id} - Delete a comment
        case 'DELETE' === $method && preg_match('#^/comments/(\d+)$#', $path, $matches):
            $commentId = (int) $matches[1];
            $db->deleteComment($commentId);
            echo json_encode(['success' => true]);
            break;

            // POST /api/submit - Submit the review
        case 'POST' === $method && '/submit' === $path:
            if (null === $body) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid request body']);
                break;
            }

            $verdict = $body['verdict'] ?? 'comment';
            $summaryNote = $body['summary_note'] ?? null;

            $db->submitReview($sessionId, $verdict, $summaryNote);
            echo json_encode(['success' => true]);
            break;

            // GET /api/status - Get session status
        case 'GET' === $method && '/status' === $path:
            $isSubmitted = $db->isSubmitted($sessionId);
            $commentCount = $db->commentCount($sessionId);
            echo json_encode([
                'session_id' => $sessionId,
                'submitted' => $isSubmitted,
                'comment_count' => $commentCount,
            ]);
            break;

            // GET /api/chat - Get all chat messages
        case 'GET' === $method && '/chat' === $path:
            $messages = $db->getChatMessages($sessionId);
            $hasPending = $db->hasPendingQuestions($sessionId);
            echo json_encode([
                'messages' => $messages,
                'has_pending' => $hasPending,
            ]);
            break;

            // POST /api/chat - Add a user question
        case 'POST' === $method && '/chat' === $path:
            if (null === $body) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid request body']);
                break;
            }

            $content = $body['content'] ?? '';
            if ('' === trim((string) $content)) {
                http_response_code(400);
                echo json_encode(['error' => 'Content is required']);
                break;
            }

            $id = $db->addChatMessage(
                sessionId: $sessionId,
                role: 'user',
                content: $content,
                fileContext: $body['file_context'] ?? null,
                lineContext: isset($body['line_context']) ? (int) $body['line_context'] : null,
            );

            echo json_encode(['id' => $id, 'success' => true]);
            break;

        default:
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
