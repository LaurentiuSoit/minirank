<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase
{
    public function testCalculateTrendImproved(): void
    {
        $this->assertSame('improved', calculateTrend(12, 37));
    }

    public function testCalculateTrendDeclined(): void
    {
        $this->assertSame('declined', calculateTrend(37, 12));
    }

    public function testCalculateTrendStable(): void
    {
        $this->assertSame('stable', calculateTrend(37, 37));
    }

    public function testCalculateTrendBoundaryStable(): void
    {
        $this->assertSame('stable', calculateTrend(1, 1));
        $this->assertSame('stable', calculateTrend(100, 100));
    }

    public function testValidateIntIdValidInteger(): void
    {
        $this->assertSame(5, validateIntId(5));
    }

    public function testValidateIntIdValidNumericString(): void
    {
        $this->assertSame(5, validateIntId('5'));
    }

    public function testValidateIntIdZeroReturnsNull(): void
    {
        $this->assertNull(validateIntId(0));
    }

    public function testValidateIntIdNegativeReturnsNull(): void
    {
        $this->assertNull(validateIntId(-1));
    }

    public function testValidateIntIdNonNumericReturnsNull(): void
    {
        $this->assertNull(validateIntId('abc'));
        $this->assertNull(validateIntId(null));
    }

    public function testValidateStringNormal(): void
    {
        $this->assertSame('hello', validateString('hello', 10));
    }

    public function testValidateStringEmptyReturnsNull(): void
    {
        $this->assertNull(validateString('', 10));
    }

    public function testValidateStringOverLengthReturnsNull(): void
    {
        $this->assertNull(validateString('hello world', 5));
    }

    public function testValidateStringTrimsWhitespace(): void
    {
        $this->assertSame('hi', validateString('  hi  ', 10));
    }

    public function testValidateStringNullReturnsNull(): void
    {
        $this->assertNull(validateString(null, 10));
    }

    public function testEscapeNormalString(): void
    {
        $this->assertSame('hello', escape('hello'));
    }

    public function testEscapeScriptTag(): void
    {
        $this->assertSame('&lt;script&gt;', escape('<script>'));
    }

    public function testEscapeDoubleQuotes(): void
    {
        $this->assertSame('&quot;', escape('"'));
    }

    public function testEscapeSingleQuotes(): void
    {
        $this->assertSame('&#039;', escape("'"));
    }

    public function testEscapeAmpersand(): void
    {
        $this->assertSame('a &lt; b &amp; c &gt; d', escape('a < b & c > d'));
    }
}