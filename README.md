# Self-Review Extension

> **Warning**
> Experimental development tool. Use at your own risk.

Human-in-the-loop code review extension for [Symfony AI Mate](https://github.com/symfony/ai-mate). AI agents can request code reviews from humans with a non-blocking, two-tool MCP pattern.

## Features

- **Two-phase review**: Start returns immediately (non-blocking), then poll for completion
- **Bidirectional chat**: Agent and reviewer can ask questions during review (blocks on responses)
- **Browser UI**: Diff viewer with inline comments and code suggestions
- **Token-efficient**: TOON format reduces output by 40-50%
- **Comment types**: question, issue, suggestion, praise, nitpick, blocker

## Installation

```bash
composer require matesofmate/self-review-extension
vendor/bin/mate discover
```

## Requirements

- PHP 8.2+
- ext-pdo_sqlite
- Git repository

## MCP Tools

### `self-review-start`

Starts a review session and opens browser. Returns immediately (non-blocking).

**Parameters:**
- `base_ref` (string): Base git reference, default: `"HEAD"`
- `head_ref` (string): Head git reference, default: `"HEAD"`
- `paths` (array): Optional file paths to filter
- `context` (string): Context message shown to reviewer
- `staged` (bool): Review staged changes instead of refs

**Returns:** Session ID for checking results later

### `self-review-result`

Checks if review is complete and retrieves results. Polls for events (review submitted or new questions) until timeout.

**Parameters:**
- `session_id` (string): Session ID from `self-review-start`
- `timeout` (int): Max seconds to wait, default: 60

**Returns:** Review results, pending questions, or waiting status

### `self-review-chat`

Gets pending questions from reviewer about the code changes.

**Parameters:**
- `session_id` (string): Session ID from `self-review-start`

**Returns:** Questions that need answers

### `self-review-answer`

Submits answer to a reviewer question.

**Parameters:**
- `session_id` (string): Session ID
- `question_id` (int): Question ID from `self-review-chat`
- `answer` (string): Your answer

## CLI Usage

Standalone CLI for blocking code review (waits for completion):

```bash
# Review changes between main and HEAD
bin/self-review --base=main

# Review staged changes
bin/self-review --staged

# Review specific files
bin/self-review --path=src/Auth.php

# With context
bin/self-review --context="Please review the auth changes"
```

Main options: `--base`, `--head`, `--staged`, `--path`, `--context`, `--port`

**Note:** Chat is disabled in CLI mode since no agent is available to answer questions.

## Development

```bash
composer install  # Install dependencies
composer test     # Run tests
composer lint     # Check quality
composer fix      # Fix code style
```

## License

MIT License - see [LICENSE](LICENSE) for details.
