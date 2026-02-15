# Self-Review Extension

Human-in-the-loop code review extension for [Symfony AI Mate](https://github.com/symfony/ai-mate). Enables AI agents to request code reviews from humans with a non-blocking, two-tool MCP pattern.

## Features

- **Non-blocking design**: Agent continues working while human reviews
- **Two-tool pattern**: `self-review-start` and `self-review-result`
- **Browser-based UI**: Clean diff viewer with comment support
- **Token-efficient output**: TOON format for 40-50% token reduction
- **Comment types**: question, issue, suggestion, praise, nitpick, blocker
- **Code suggestions**: Monaco editor for suggesting code changes
- **Verdicts**: approved, changes_requested, comment

## Installation

```bash
composer require matesofmate/self-review-extension
```

## Requirements

- PHP 8.2+
- ext-pdo_sqlite
- Git repository

## MCP Tools

### `self-review-start`

Start a human code review session. Opens a browser window for the user to review git changes and add comments.

**Parameters:**

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `base_ref` | string | `"main"` | Base git reference to compare from |
| `head_ref` | string | `"HEAD"` | Head git reference to compare to |
| `paths` | array | `[]` | File paths to filter (empty = all) |
| `context` | string | `""` | Context message to show reviewer |

**Returns:** Session ID and URL immediately (non-blocking)

```json
{
  "status": "started",
  "session": {
    "id": "abc123def456",
    "url": "http://localhost:8080",
    "files": 3
  },
  "instructions": "Review opened in browser. Call self-review-result with session_id when ready."
}
```

### `self-review-result`

Check if a human review session is complete and get the results.

**Parameters:**

| Name | Type | Description |
|------|------|-------------|
| `session_id` | string | Session ID from `self-review-start` |

**Returns:** Either "waiting" status or complete review results

```json
{
  "status": "submitted",
  "session_id": "abc123def456",
  "verdict": "changes_requested",
  "summary": "Please address the security concern",
  "stats": {
    "files": 3,
    "comments": 2,
    "by_tag": {"issue": 1, "suggestion": 1}
  },
  "comments": {
    "src/Auth.php": [
      {"lines": "45", "tag": "issue", "body": "SQL injection vulnerability"}
    ]
  }
}
```

## CLI Usage

The extension includes a standalone CLI for blocking code review:

```bash
# Review changes between main and HEAD
bin/self-review --base=main

# Review specific files
bin/self-review --path=src/Auth.php --path=src/User.php

# With context message
bin/self-review --context="Please review the authentication changes"

# Output to file
bin/self-review --output=review.json --format=json
```

**Options:**

| Option | Short | Description |
|--------|-------|-------------|
| `--base` | `-b` | Base git reference (default: main) |
| `--head` | `-H` | Head git reference (default: HEAD) |
| `--path` | `-p` | File paths to filter (repeatable) |
| `--context` | `-c` | Context message for reviewer |
| `--port` | | Preferred port number |
| `--format` | `-f` | Output format: toon, json |
| `--output` | `-o` | Output file path |

## Agent Workflow

```
Agent: "I'll start a review for my changes"
  → calls self-review-start with context="I added input validation to the form handler"
  → receives session_id, continues working

[Human reviews in browser, adds comments, submits]

Agent: "Let me check if the review is ready"
  → calls self-review-result with session_id
  → receives verdict and comments

Agent: "The review requested changes. I'll address the feedback"
  → reads comments, makes changes
```

## Comment Types

| Tag | Color | Use Case |
|-----|-------|----------|
| `question` | Blue | Asking for clarification |
| `issue` | Red | Something that needs fixing |
| `suggestion` | Green | Improvement idea with optional code |
| `praise` | Yellow | Highlighting good code |
| `nitpick` | Gray | Minor style/preference |
| `blocker` | Dark Red | Must be fixed before merging |

## Verdict Options

- **approved**: Changes look good, ready to merge
- **changes_requested**: Issues need to be addressed
- **comment**: Feedback only, no verdict

## Development

```bash
# Install dependencies
composer install

# Run tests
composer test

# Check code quality
composer lint

# Fix code style
composer fix
```

## Architecture

```
src/
├── Capability/
│   └── SelfReviewTool.php      # MCP tool with start/result methods
├── Command/
│   └── ReviewCommand.php       # Standalone CLI command
├── Git/
│   ├── DiffParser.php          # Unified diff parsing
│   ├── GitDiffResolver.php     # Git command execution
│   ├── DiffResult.php          # Collection of changed files
│   ├── ChangedFile.php         # Single file changes
│   └── DiffHunk.php            # Diff hunk data
├── Formatter/
│   └── ToonFormatter.php       # TOON output formatting
├── Output/
│   ├── ReviewComment.php       # Comment value object
│   └── ReviewResult.php        # Result value object
├── Server/
│   ├── ReviewSession.php       # Session lifecycle
│   ├── ReviewSessionFactory.php
│   ├── router.php              # PHP built-in server router
│   └── api.php                 # REST API endpoints
└── Storage/
    ├── Database.php            # SQLite wrapper
    ├── DatabaseFactory.php
    └── schema.sql              # Table definitions
```

## License

MIT License - see [LICENSE](LICENSE) for details.
