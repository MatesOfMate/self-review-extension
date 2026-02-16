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

use MatesOfMate\SelfReviewExtension\Server\ReviewSession;
use PHPUnit\Framework\TestCase;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
class ReviewSessionTest extends TestCase
{
    public function testConstructorSignatureIncludesChatEnabled(): void
    {
        $reflection = new \ReflectionClass(ReviewSession::class);
        $constructor = $reflection->getConstructor();

        $this->assertNotNull($constructor, 'ReviewSession should have a constructor');

        $parameters = $constructor->getParameters();

        // Find chatEnabled parameter
        $chatEnabledParam = null;
        foreach ($parameters as $param) {
            if ('chatEnabled' === $param->getName()) {
                $chatEnabledParam = $param;
                break;
            }
        }

        $this->assertNotNull($chatEnabledParam, 'Constructor should have a chatEnabled parameter');
        $this->assertTrue($chatEnabledParam->isDefaultValueAvailable(), 'chatEnabled should have a default value');
        $this->assertTrue($chatEnabledParam->getDefaultValue(), 'chatEnabled should default to true');
    }

    public function testIsChatEnabledMethodExists(): void
    {
        $reflection = new \ReflectionClass(ReviewSession::class);

        $this->assertTrue(
            $reflection->hasMethod('isChatEnabled'),
            'ReviewSession should have isChatEnabled() method'
        );

        $method = $reflection->getMethod('isChatEnabled');
        $this->assertTrue($method->isPublic(), 'isChatEnabled() should be public');
        $this->assertSame('bool', (string) $method->getReturnType());
    }
}
