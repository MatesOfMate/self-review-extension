<?php

/*
 * This file is part of the MatesOfMate Organisation.
 *
 * (c) Johannes Wachter <johannes@sulu.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use MatesOfMate\SelfReviewExtension\Capability\SelfReviewTool;
use MatesOfMate\SelfReviewExtension\Formatter\ToonFormatter;
use MatesOfMate\SelfReviewExtension\Git\DiffParser;
use MatesOfMate\SelfReviewExtension\Git\GitDiffResolver;
use MatesOfMate\SelfReviewExtension\Server\ReviewSessionFactory;
use MatesOfMate\SelfReviewExtension\Storage\DatabaseFactory;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    // Git layer
    $services->set(DiffParser::class);
    $services->set(GitDiffResolver::class)
        ->arg('$projectRoot', '%mate.root_dir%');

    // Storage layer
    $services->set(DatabaseFactory::class);

    // Formatter
    $services->set(ToonFormatter::class);

    // Server layer
    $services->set(ReviewSessionFactory::class)
        ->arg('$projectRoot', '%mate.root_dir%');

    // Tools - automatically discovered by #[McpTool] attribute
    $services->set(SelfReviewTool::class);
};
