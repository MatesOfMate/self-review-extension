<?php

/*
 * This file is part of the MatesOfMate Organisation.
 *
 * (c) Johannes Wachter <johannes@sulu.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace MatesOfMate\SelfReviewExtension\Tests\Unit\Server;

use MatesOfMate\SelfReviewExtension\Server\ReviewSessionFactory;
use MatesOfMate\SelfReviewExtension\Storage\DatabaseFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
class ReviewSessionFactoryTest extends TestCase
{
    private DatabaseFactory&MockObject $databaseFactory;

    protected function setUp(): void
    {
        $this->databaseFactory = $this->createMock(DatabaseFactory::class);
    }

    public function testCreateMethodSignatureIncludesChatEnabled(): void
    {
        $factory = new ReviewSessionFactory($this->databaseFactory, '/tmp');

        $reflection = new \ReflectionMethod($factory, 'create');
        $parameters = $reflection->getParameters();

        // Find chatEnabled parameter
        $chatEnabledParam = null;
        foreach ($parameters as $param) {
            if ('chatEnabled' === $param->getName()) {
                $chatEnabledParam = $param;
                break;
            }
        }

        $this->assertNotNull($chatEnabledParam, 'create() should have a chatEnabled parameter');
        $this->assertTrue($chatEnabledParam->isDefaultValueAvailable(), 'chatEnabled should have a default value');
        $this->assertTrue($chatEnabledParam->getDefaultValue(), 'chatEnabled should default to true');
    }

    public function testCreateAcceptsChatEnabledFalse(): void
    {
        // This test verifies the method signature accepts chatEnabled: false
        // We can't fully test the behavior without side effects, but we verify the signature

        $factory = new ReviewSessionFactory($this->databaseFactory, '/tmp');

        $reflection = new \ReflectionMethod($factory, 'create');
        $parameters = $reflection->getParameters();

        // Verify method signature
        $this->assertCount(3, $parameters);
        $this->assertSame('diff', $parameters[0]->getName());
        $this->assertSame('context', $parameters[1]->getName());
        $this->assertSame('chatEnabled', $parameters[2]->getName());
    }
}
