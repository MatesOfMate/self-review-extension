# CLAUDE.md

Development guidance for the self-review extension.

## Overview

Human-in-the-loop code review extension for Symfony AI Mate. Two-tool non-blocking MCP pattern where `self-review-start` opens a browser and returns immediately, `self-review-result` checks for completion.

## Project Structure

```
self-review/
├── bin/self-review              # CLI entry point
├── config/config.php            # Symfony DI service registration
├── public/index.html            # Browser-based review UI
├── src/
│   ├── Capability/              # MCP tools
│   ├── Command/                 # CLI command
│   ├── Git/                     # Diff parsing and resolution
│   ├── Formatter/               # TOON output
│   ├── Output/                  # Result value objects
│   ├── Server/                  # HTTP server and API
│   └── Storage/                 # SQLite persistence
└── tests/Unit/                  # PHPUnit tests
```

## Key Patterns

### Non-Blocking MCP Design

The tool uses a non-blocking pattern to avoid blocking the agent:

1. `self-review-start`: Resolves git diff, starts PHP server, opens browser, returns session ID
2. Agent continues working
3. `self-review-result`: Checks if human submitted, returns results or "waiting" status

### Session Management

- Sessions stored in SQLite (temp directory)
- PHP built-in server serves review UI
- Sessions expire after 1 hour (TTL)
- Cleanup on result collection or destruct

### TOON Output

Uses `helgesverre/toon` for token-efficient output. Comments grouped by file to minimize tokens.

## Commands

```bash
# Install dependencies
composer install

# Run tests
composer test

# Check code quality
composer lint

# Fix code style
composer fix

# Run specific test
vendor/bin/phpunit tests/Unit/Git/DiffParserTest.php
```

## Adding Features

### New Comment Tags

1. Add constant in `ReviewComment.php`
2. Update frontend styles in `public/index.html`
3. Add to database validation if needed

### New API Endpoints

1. Add route in `src/Server/api.php`
2. Add database method if needed in `Database.php`
3. Update frontend to use new endpoint

### New Output Formats

1. Add method to `ToonFormatter.php`
2. Update CLI command options

## Testing

Tests mirror `src/` structure. Key test files:

- `DiffParserTest.php` - Diff parsing edge cases
- `DatabaseTest.php` - SQLite operations
- `ToonFormatterTest.php` - Output formatting
- `SelfReviewToolTest.php` - Tool behavior

Use in-memory SQLite for fast database tests.

## Code Standards

- PHPStan level 8
- PHP CS Fixer with Symfony rules
- Rector for PHP 8.2+ patterns
- No `declare(strict_types=1)` per matesofmate convention
- MatesOfMate header comment on all files
- `@internal` annotation for implementation details
