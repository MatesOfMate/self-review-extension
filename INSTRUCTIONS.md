# Self-Review Extension Instructions

This document provides guidance for AI agents on how to effectively use the self-review extension.

## When to Use

Use self-review when you want human feedback on:

- Code changes before committing
- Refactoring decisions
- Security-sensitive code
- Architecture changes
- Complex bug fixes
- New feature implementations

## Basic Workflow

### 1. Start a Review

Call `self-review-start` with a clear context message explaining:

- What you changed
- Why you made these changes
- What kind of feedback you need

```
self-review-start(
  base_ref="main",
  head_ref="HEAD",
  context="I refactored the authentication flow to use middleware instead of controller checks. Please verify the security implications."
)
```

### 2. Continue Working

The tool returns immediately with a session ID. You can:

- Continue other tasks
- Prepare follow-up changes
- Work on unrelated features

### 3. Check for Results

Periodically call `self-review-result` with the session ID:

```
self-review-result(session_id="abc123def456")
```

**If waiting**: The human hasn't submitted yet. Try again later.

**If submitted**: Parse the verdict and comments.

### 4. Process Feedback

Based on the verdict:

- **approved**: Proceed with your changes
- **changes_requested**: Address the comments before proceeding
- **comment**: Informational feedback, use your judgment

## Understanding Comments

### Comment Tags

| Tag | Meaning | Action |
|-----|---------|--------|
| `blocker` | Critical issue, must fix | Fix immediately before proceeding |
| `issue` | Problem that needs attention | Address in current changes |
| `suggestion` | Improvement idea | Consider implementing |
| `question` | Clarification needed | Provide explanation or context |
| `nitpick` | Minor preference | Optional to address |
| `praise` | Positive feedback | Continue this pattern |

### Code Suggestions

Comments may include code suggestions. These are ready-to-use code snippets that you can apply directly.

## Best Practices

### Good Context Messages

**Do:**
```
"Added input validation to UserController::update().
Validates email format and password strength.
Please check if the validation rules are appropriate."
```

**Don't:**
```
"Made some changes"
```

### Scoping Reviews

For large changesets, filter to specific files:

```
self-review-start(
  base_ref="main",
  paths=["src/Security/", "src/Auth/"],
  context="Security-related changes only"
)
```

### Handling Blocking Comments

If a review has `has_blockers: true`:

1. Read all blocker comments carefully
2. Address each issue
3. Request a new review if substantial changes were made

## Error Handling

### Session Not Found

The session may have:
- Expired (1 hour TTL)
- Been closed by the server stopping
- Never existed (wrong ID)

**Solution**: Start a new review session.

### No Changes Found

No diff between base_ref and head_ref.

**Solution**: Check your refs are correct and there are actual changes.

### Server Stopped

The PHP built-in server stopped unexpectedly.

**Solution**: Start a new review session.

## Tips

1. **Be specific in context**: The more context you provide, the better feedback you'll get.

2. **Check result promptly**: Don't leave reviews open too long (1 hour TTL).

3. **Address all blockers**: Never proceed with unresolved blockers.

4. **Learn from patterns**: If you see repeated feedback, incorporate it into your approach.

5. **Use for important decisions**: Reserve human review for significant changes that benefit from human judgment.
