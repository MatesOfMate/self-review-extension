<?php

/*
 * This file is part of the MatesOfMate Organisation.
 *
 * (c) Johannes Wachter <johannes@sulu.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace MatesOfMate\SelfReviewExtension\Command;

use MatesOfMate\SelfReviewExtension\Formatter\ToonFormatter;
use MatesOfMate\SelfReviewExtension\Git\DiffParser;
use MatesOfMate\SelfReviewExtension\Git\GitDiffResolver;
use MatesOfMate\SelfReviewExtension\Server\ReviewSessionFactory;
use MatesOfMate\SelfReviewExtension\Storage\DatabaseFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Standalone CLI command for blocking code review.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
class ReviewCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('review')
            ->setDescription('Start a human-in-the-loop code review session')
            ->addOption('base', 'b', InputOption::VALUE_REQUIRED, 'Base git reference', 'HEAD')
            ->addOption('head', 'H', InputOption::VALUE_REQUIRED, 'Head git reference', 'HEAD')
            ->addOption('staged', 's', InputOption::VALUE_NONE, 'Review staged changes (git diff --cached)')
            ->addOption('path', 'p', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'File paths to filter', [])
            ->addOption('context', 'c', InputOption::VALUE_REQUIRED, 'Context message for reviewer', '')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Preferred port number')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format (toon, json)', 'toon')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output file path');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $baseRef */
        $baseRef = $input->getOption('base');
        /** @var string $headRef */
        $headRef = $input->getOption('head');
        $staged = (bool) $input->getOption('staged');
        /** @var list<string> $paths */
        $paths = $input->getOption('path');
        /** @var string $context */
        $context = $input->getOption('context');
        /** @var string $format */
        $format = $input->getOption('format');
        /** @var string|null $outputFile */
        $outputFile = $input->getOption('output');

        $cwd = getcwd();
        if (false === $cwd) {
            $io->error('Could not determine current working directory');

            return Command::FAILURE;
        }

        // Initialize components
        $parser = new DiffParser();
        $diffResolver = new GitDiffResolver($parser, $cwd);
        $databaseFactory = new DatabaseFactory();
        $sessionFactory = new ReviewSessionFactory($databaseFactory, $cwd);
        $formatter = new ToonFormatter();

        // Resolve diff based on mode
        if ($staged) {
            // Review staged changes
            if (!$diffResolver->hasStagedChanges()) {
                $io->warning('No staged changes found. Use "git add" to stage changes first.');

                return Command::SUCCESS;
            }

            $io->text(\sprintf('Reviewing staged changes against %s', $baseRef));
            $diff = $diffResolver->resolveStaged($baseRef, $paths);
        } else {
            // Validate refs
            if (!$diffResolver->refExists($baseRef)) {
                $io->error(\sprintf('Base ref "%s" does not exist', $baseRef));

                return Command::FAILURE;
            }

            if (!$diffResolver->refExists($headRef)) {
                $io->error(\sprintf('Head ref "%s" does not exist', $headRef));

                return Command::FAILURE;
            }

            // Resolve diff between refs
            $io->text(\sprintf('Comparing %s...%s', $baseRef, $headRef));
            $diff = $diffResolver->resolve($baseRef, $headRef, $paths);

            if ($diff->isEmpty()) {
                // Check if there are staged changes and suggest using --staged
                if ($diffResolver->hasStagedChanges()) {
                    $io->warning('No changes found between refs, but there are staged changes. Try: bin/self-review --staged');
                } else {
                    $io->warning('No changes found between the specified refs');
                }

                return Command::SUCCESS;
            }
        }

        if ($diff->isEmpty()) {
            $io->warning('No changes found');

            return Command::SUCCESS;
        }

        $io->text(\sprintf('Found %d changed file(s)', $diff->getFileCount()));

        // Create session
        $session = $sessionFactory->create(
            $diff,
            '' !== $context ? $context : null
        );

        $io->success(\sprintf('Review opened at %s', $session->getUrl()));
        $io->text('Waiting for review to be submitted...');
        $io->text('Press Ctrl+C to cancel');

        // Wait for submission (blocking)
        while (!$session->isSubmitted()) {
            if ($session->isExpired()) {
                $session->shutdown();
                $io->error('Session expired (TTL: 1 hour)');

                return Command::FAILURE;
            }

            if (!$session->isRunning()) {
                $io->error('Review server stopped unexpectedly');

                return Command::FAILURE;
            }

            // Check every second
            sleep(1);
        }

        // Collect results
        $result = $session->collectResult();
        $session->shutdown();

        if (!$result instanceof \MatesOfMate\SelfReviewExtension\Output\ReviewResult) {
            $io->error('Failed to collect review results');

            return Command::FAILURE;
        }

        // Format output
        $outputContent = 'toon' === $format
            ? $formatter->formatResult($result)
            : json_encode($result->toArray(), \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT);

        // Write output
        if (null !== $outputFile) {
            file_put_contents($outputFile, $outputContent);
            $io->success(\sprintf('Review results written to %s', $outputFile));
        } else {
            $output->writeln($outputContent);
        }

        // Summary
        $io->newLine();
        $io->text(\sprintf('Verdict: %s', $result->verdict ?? 'none'));
        $io->text(\sprintf('Comments: %d', $result->getCommentCount()));

        if ($result->hasBlockingComments()) {
            $io->warning('Review has blocking comments!');
        }

        return Command::SUCCESS;
    }
}
