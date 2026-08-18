<?php
declare(strict_types=1);

require_once __DIR__ . '/../seed.php';

use PHPUnit\Framework\TestCase;

final class SeedTest extends TestCase
{
    public function testClampBelowMin(): void
    {
        $this->assertSame(1, clamp(0, 1, 100));
    }

    public function testClampAboveMax(): void
    {
        $this->assertSame(100, clamp(101, 1, 100));
    }

    public function testClampBoundaryMin(): void
    {
        $this->assertSame(1, clamp(1, 1, 100));
    }

    public function testClampBoundaryMax(): void
    {
        $this->assertSame(100, clamp(100, 1, 100));
    }

    public function testClampNormalValue(): void
    {
        $this->assertSame(50, clamp(50, 1, 100));
    }

    public function testGenerateWalkReturnsCorrectCount(): void
    {
        $walk = generateWalk(50, 30);
        $this->assertCount(30, $walk);
    }

    public function testGenerateWalkValuesInRange(): void
    {
        $walk = generateWalk(50, 30);
        foreach ($walk as $position) {
            $this->assertGreaterThanOrEqual(1, $position);
            $this->assertLessThanOrEqual(100, $position);
        }
    }

    public function testGenerateWalkKeysAreDateStrings(): void
    {
        $walk = generateWalk(50, 30);
        $keys = array_keys($walk);
        foreach ($keys as $key) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $key);
        }
    }

    public function testGenerateWalkLastKeyIsToday(): void
    {
        $walk = generateWalk(50, 30);
        $keys = array_keys($walk);
        $this->assertSame(date('Y-m-d'), end($keys));
    }

    public function testGenerateWalkFirstKeyIs29DaysAgo(): void
    {
        $walk = generateWalk(50, 30);
        $keys = array_keys($walk);
        $expectedKey = date('Y-m-d', strtotime('-29 days'));
        $this->assertSame($expectedKey, $keys[0]);
    }

    public function testGenerateWalkFirstValueEqualsBase(): void
    {
        $walk = generateWalk(50, 30);
        $values = array_values($walk);
        $this->assertSame(50, $values[0]);
    }

    public function testGenerateWalkSingleDay(): void
    {
        $walk = generateWalk(1, 1);
        $this->assertCount(1, $walk);
        $this->assertSame(1, array_values($walk)[0]);

        $walk = generateWalk(100, 1);
        $this->assertCount(1, $walk);
        $this->assertSame(100, array_values($walk)[0]);
    }
}