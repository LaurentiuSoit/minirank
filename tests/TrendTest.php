<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class TrendTest extends TestCase
{
    private function getPdo(): PDO
    {
        return $GLOBALS['testPdo'];
    }

    private function insertPosition(PDO $pdo, int $keywordId, int $position, string $date): void
    {
        $stmt = $pdo->prepare('INSERT INTO positions (keyword_id, position, recorded_at) VALUES (?, ?, ?)');
        $stmt->execute([$keywordId, $position, $date]);
    }

    public function testGetKeywordTrendReturnsImproved(): void
    {
        $pdo = $this->getPdo();
        $today = date('Y-m-d');
        $weekAgo = date('Y-m-d', strtotime('-7 days'));

        $this->insertPosition($pdo, 1, 37, $weekAgo);
        $this->insertPosition($pdo, 1, 12, $today);

        $result = getKeywordTrend($pdo, 1);
        $this->assertSame('improved', $result);
    }

    public function testGetKeywordTrendReturnsDeclined(): void
    {
        $pdo = $this->getPdo();
        $today = date('Y-m-d');
        $weekAgo = date('Y-m-d', strtotime('-7 days'));

        $this->insertPosition($pdo, 2, 12, $weekAgo);
        $this->insertPosition($pdo, 2, 37, $today);

        $result = getKeywordTrend($pdo, 2);
        $this->assertSame('declined', $result);
    }

    public function testGetKeywordTrendReturnsStable(): void
    {
        $pdo = $this->getPdo();
        $today = date('Y-m-d');
        $weekAgo = date('Y-m-d', strtotime('-7 days'));

        $this->insertPosition($pdo, 3, 37, $weekAgo);
        $this->insertPosition($pdo, 3, 37, $today);

        $result = getKeywordTrend($pdo, 3);
        $this->assertSame('stable', $result);
    }

    public function testGetKeywordTrendReturnsNullWhenOnlyToday(): void
    {
        $pdo = $this->getPdo();
        $today = date('Y-m-d');

        $this->insertPosition($pdo, 4, 50, $today);

        $result = getKeywordTrend($pdo, 4);
        $this->assertNull($result);
    }

    public function testGetKeywordTrendReturnsNullWhenOnlyWeekAgo(): void
    {
        $pdo = $this->getPdo();
        $weekAgo = date('Y-m-d', strtotime('-7 days'));

        $this->insertPosition($pdo, 5, 50, $weekAgo);

        $result = getKeywordTrend($pdo, 5);
        $this->assertNull($result);
    }

    public function testGetKeywordTrendReturnsNullWhenNoPositions(): void
    {
        $pdo = $this->getPdo();

        $result = getKeywordTrend($pdo, 6);
        $this->assertNull($result);
    }
}