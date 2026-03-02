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
 * Watchdog process that terminates a stale PHP review server.
 *
 * Runs as a background process alongside the PHP built-in server.
 * Kills the server process when no browser ping or agent activity for --stale-seconds.
 *
 * Usage: php watchdog.php --db-path=... --session-id=... --server-pid=... [--stale-seconds=600]
 */
$rawOpts = getopt('', ['db-path:', 'session-id:', 'server-pid:', 'stale-seconds:']);

if (false === $rawOpts) {
    exit(1);
}

$dbPath = isset($rawOpts['db-path']) && is_string($rawOpts['db-path']) ? $rawOpts['db-path'] : null;
$sessionId = isset($rawOpts['session-id']) && is_string($rawOpts['session-id']) ? $rawOpts['session-id'] : null;
$serverPid = isset($rawOpts['server-pid']) && is_string($rawOpts['server-pid']) ? (int) $rawOpts['server-pid'] : 0;
$staleSeconds = isset($rawOpts['stale-seconds']) && is_string($rawOpts['stale-seconds']) ? (int) $rawOpts['stale-seconds'] : 600;

if (null === $dbPath || null === $sessionId || 0 === $serverPid) {
    exit(1);
}

function watchdog_is_process_running(int $pid): bool
{
    if ('Windows' === \PHP_OS_FAMILY) {
        $output = shell_exec(sprintf('tasklist /FI "PID eq %d" 2>NUL', $pid));

        return is_string($output) && str_contains($output, (string) $pid);
    }

    $output = shell_exec(sprintf('kill -0 %d 2>/dev/null && echo alive', $pid));

    return is_string($output) && str_contains($output, 'alive');
}

function watchdog_kill_process(int $pid): void
{
    if ('Windows' === \PHP_OS_FAMILY) {
        shell_exec(sprintf('taskkill /F /PID %d 2>NUL', $pid));

        return;
    }

    shell_exec(sprintf('kill -15 %d 2>/dev/null', $pid));
}

/**
 * Returns 'stale', 'done', 'alive', or 'error'.
 * PDO is created and destroyed within this function scope.
 *
 * @return 'alive'|'done'|'error'|'stale'
 */
function watchdog_check_session(string $dbPath, string $sessionId, int $staleSeconds): string
{
    $pdo = new PDO(
        sprintf('sqlite:%s', $dbPath),
        null,
        null,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $stmt = $pdo->prepare('SELECT last_ping_at, status FROM sessions WHERE id = ?');
    $stmt->execute([$sessionId]);

    /** @var array{last_ping_at: string|null, status: string}|false $row */
    $row = $stmt->fetch();

    if (false === $row) {
        return 'done';
    }

    if ('submitted' === $row['status']) {
        return 'done';
    }

    $lastPingAt = $row['last_ping_at'];
    if (null !== $lastPingAt) {
        $lastPingTime = strtotime($lastPingAt);
        if (false !== $lastPingTime && (time() - $lastPingTime) > $staleSeconds) {
            return 'stale';
        }
    }

    return 'alive';
}

while (true) {
    sleep(30);

    // Server already gone, nothing left to watch
    if (!watchdog_is_process_running($serverPid)) {
        exit(0);
    }

    // Check staleness via SQLite — PDO is scoped inside the function and released on return
    try {
        $result = watchdog_check_session($dbPath, $sessionId, $staleSeconds);

        if ('done' === $result) {
            exit(0);
        }

        if ('stale' === $result) {
            watchdog_kill_process($serverPid);
            exit(0);
        }
    } catch (Throwable) {
        // DB locked or missing - skip this cycle and retry
    }
}
