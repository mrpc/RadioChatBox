<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use RadioChatBox\Services\RankService;

/**
 * Activity ranks derived purely from a message count: correct tier at each
 * boundary, the next-tier threshold, and clamping of negatives.
 */
class RankServiceTest extends TestCase
{
    /** Each boundary maps to the expected tier title. */
    public function testTierBoundaries(): void
    {
        $this->assertSame('Newcomer', RankService::forCount(0)['title']);
        $this->assertSame('Newcomer', RankService::forCount(9)['title']);
        $this->assertSame('Regular', RankService::forCount(10)['title']);
        $this->assertSame('Active', RankService::forCount(50)['title']);
        $this->assertSame('Veteran', RankService::forCount(200)['title']);
        $this->assertSame('Legend', RankService::forCount(1000)['title']);
        $this->assertSame('Legend', RankService::forCount(999999)['title']);
    }

    /** next_at points at the following tier's threshold, null at the top. */
    public function testNextThreshold(): void
    {
        $this->assertSame(10, RankService::forCount(0)['next_at']);
        $this->assertSame(1000, RankService::forCount(200)['next_at']);
        $this->assertNull(RankService::forCount(5000)['next_at']);
    }

    /** Negative counts clamp to the lowest tier. */
    public function testNegativeClamps(): void
    {
        $rank = RankService::forCount(-5);
        $this->assertSame('Newcomer', $rank['title']);
        $this->assertSame(0, $rank['level']);
    }

    /** The level index increases with the tier. */
    public function testLevelIncreases(): void
    {
        $this->assertSame(0, RankService::forCount(0)['level']);
        $this->assertSame(4, RankService::forCount(1000)['level']);
    }
}
