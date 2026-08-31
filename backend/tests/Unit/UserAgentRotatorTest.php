<?php

namespace Tests\Unit;

use App\Services\Scraping\UserAgentRotator;
use PHPUnit\Framework\TestCase;

class UserAgentRotatorTest extends TestCase
{
    public function test_it_uses_each_agent_before_repeating(): void
    {
        $rotator = new UserAgentRotator(['agent-a', 'agent-b', 'agent-c']);

        $firstCycle = [$rotator->next(), $rotator->next(), $rotator->next()];

        self::assertCount(3, array_unique($firstCycle));
        self::assertSame($firstCycle[0], $rotator->next());
    }
}
