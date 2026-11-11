<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\General;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\General\NumberFormat;

/**
 * Numbers and money in the reader's language: the framework's conventions, the application's
 * own over them, and a symbol before or after.
 */
#[CoversClass(NumberFormat::class)]
class NumberFormatTest extends TestCase
{
    /** The application's own conventions, put back. */
    protected function tearDown(): void
    {
        unset(Application::getInstance()->applicationInfo['numbers']);
        parent::tearDown();
    }

    /**
     * English writes a dot for the decimals and the symbol first; a language not listed is
     * written as the framework always wrote numbers — a comma, the symbol after — so nothing
     * changes for it. A currency with no symbol of its own is written with its code, after.
     */
    public function testTheConventionsOfEachLanguage(): void
    {
        // Act
        $english = [NumberFormat::number(1234.5, 2, 'en'), NumberFormat::money(1234.5, 'EUR', 2, 'en'), NumberFormat::money(-80, 'gbp', 2, 'en')];
        $greek   = [NumberFormat::number(1234.5, 2, 'el'), NumberFormat::money(1234.5, 'EUR', 2, 'el'), NumberFormat::money(9, 'SEK', 0, 'el')];
        $coded   = NumberFormat::money(9, 'SEK', 0, 'en');

        // Assert
        $this->assertSame(['1,234.50', '€1,234.50', '-£80.00'], $english);
        $this->assertSame(['1.234,50', '1.234,50 €', '9 SEK'], $greek);
        $this->assertSame('9 SEK', $coded, 'a code, not a symbol: after the number in any language');
    }

    /** Trailing noughts after the decimal mark go; a whole number keeps its own. */
    public function testTrimmedLeavesWholeNumbersAlone(): void
    {
        // Act
        $values = [NumberFormat::trimmed(1234.5, 2, 'en'), NumberFormat::trimmed(120, 2, 'el'), NumberFormat::trimmed(120, 0, 'el')];

        // Assert
        $this->assertSame(['1,234.5', '120', '120'], $values);
    }

    /** `'numbers'` in app.php changes a language's convention, keeping what it does not name. */
    public function testTheApplicationsOwnConvention(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['numbers'] = ['fr' => ['thousands' => ' ']];

        // Act
        $french = NumberFormat::money(1234.5, 'EUR', 2, 'fr');

        // Assert
        $this->assertSame('1 234,50 €', $french);
    }

    /**
     * What a model call costs (FW-461): a total with two decimals, a fraction of a cent with as many
     * as it takes not to read as nought — up to six — in the page's language's punctuation.
     */
    public function testSmallMoneyIsNeverNought(): void
    {
        // Act
        $values = [NumberFormat::smallMoney(1.0488, 'USD', 'el'), NumberFormat::smallMoney(1.0488, 'USD', 'en'), NumberFormat::smallMoney(0.0034, 'USD', 'el'),
            NumberFormat::smallMoney(0.00000012, 'USD', 'en'), NumberFormat::smallMoney(0, 'USD', 'en'), NumberFormat::number(34571857, 0, 'el')];

        // Assert
        $this->assertSame(['1,05 $', '$1.05', '0,0034 $', '$0.000000', '$0.00', '34.571.857'], $values);
    }
}
