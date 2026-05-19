<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\General;

use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the global helper functions defined in src/Pramnos/helpers.php.
 *
 * All tested functions are pure (or near-pure) and require no database or HTTP
 * context:
 *   - env()           — constant lookup with default fallback
 *   - envvar()        — environment variable lookup across getenv/$_ENV/$_SERVER
 *   - parseEnvValue() — string-to-PHP-type conversion
 *   - e()             — HTML-safe string encoding
 *
 * The functions are autoloaded by Composer; no manual require is needed.
 */
#[CoversFunction('env')]
#[CoversFunction('envvar')]
#[CoversFunction('parseEnvValue')]
#[CoversFunction('e')]
class GlobalHelpersTest extends TestCase
{
    // ── env() ─────────────────────────────────────────────────────────────────

    /**
     * env() must return the value of a defined constant.
     *
     * This covers the `if (defined($field)) return constant($field)` branch.
     */
    public function testEnvReturnsDefinedConstantValue(): void
    {
        // Arrange — ROOT is defined in bootstrap.php
        $this->assertTrue(defined('ROOT'), 'pre-condition: ROOT must be defined');

        // Act
        $result = env('ROOT');

        // Assert
        $this->assertSame(ROOT, $result,
            'env() must return the constant value when the constant is defined');
    }

    /**
     * env() must return the $defaultReturn when the constant is not defined.
     *
     * This covers the `return $defaultReturn` fallback.
     */
    public function testEnvReturnsDefaultForUndefinedConstant(): void
    {
        // Arrange — use a name that is certainly not defined
        $fakeName = 'PRAMNOS_TEST_UNDEFINED_CONST_' . bin2hex(random_bytes(4));
        $this->assertFalse(defined($fakeName), 'pre-condition: constant must not exist');

        // Act
        $result = env($fakeName, 'fallback_value');

        // Assert
        $this->assertSame('fallback_value', $result);
    }

    /**
     * env() must return null when no default is supplied and the constant is
     * not defined.
     */
    public function testEnvReturnsNullByDefaultForUndefinedConstant(): void
    {
        // Arrange
        $fakeName = 'PRAMNOS_TEST_MISSING_' . bin2hex(random_bytes(4));

        // Act
        $result = env($fakeName);

        // Assert
        $this->assertNull($result);
    }

    // ── parseEnvValue() ───────────────────────────────────────────────────────

    /**
     * parseEnvValue() must convert the string 'true' to boolean true.
     *
     * This covers the 'true' / '(true)' cases (lines ~57-60).
     */
    public function testParseEnvValueConvertsStringTrueToBoolean(): void
    {
        // Assert
        $this->assertTrue(parseEnvValue('true'),   '"true" → true');
        $this->assertTrue(parseEnvValue('(true)'), '"(true)" → true');
        $this->assertTrue(parseEnvValue('TRUE'),   '"TRUE" → true (case-insensitive)');
    }

    /**
     * parseEnvValue() must convert the string 'false' to boolean false.
     *
     * This covers the 'false' / '(false)' cases (lines ~61-64).
     */
    public function testParseEnvValueConvertsStringFalseToBoolean(): void
    {
        // Assert
        $this->assertFalse(parseEnvValue('false'),   '"false" → false');
        $this->assertFalse(parseEnvValue('(false)'), '"(false)" → false');
    }

    /**
     * parseEnvValue() must convert the string 'null' to PHP null.
     *
     * This covers the 'null' / '(null)' cases (lines ~65-68).
     */
    public function testParseEnvValueConvertsStringNullToNull(): void
    {
        // Assert
        $this->assertNull(parseEnvValue('null'),   '"null" → null');
        $this->assertNull(parseEnvValue('(null)'), '"(null)" → null');
    }

    /**
     * parseEnvValue() must strip surrounding double quotes.
     *
     * This covers the `substr($trimmedValue, 0, 1) === '"'` branch (line ~70).
     */
    public function testParseEnvValueStripsDoubleQuotes(): void
    {
        // Act
        $result = parseEnvValue('"hello world"');

        // Assert
        $this->assertSame('hello world', $result);
    }

    /**
     * parseEnvValue() must strip surrounding single quotes.
     *
     * This covers the `substr($trimmedValue, 0, 1) === "'"` branch (line ~72).
     */
    public function testParseEnvValueStripsSingleQuotes(): void
    {
        // Act
        $result = parseEnvValue("'hello'");

        // Assert
        $this->assertSame('hello', $result);
    }

    /**
     * parseEnvValue() must convert a numeric integer string to int.
     *
     * This covers the `is_numeric` → `(int)` branch (line ~79).
     */
    public function testParseEnvValueConvertsIntegerStringToInt(): void
    {
        // Assert
        $this->assertSame(42,  parseEnvValue('42'));
        $this->assertSame(-1,  parseEnvValue('-1'));
        $this->assertSame(0,   parseEnvValue('0'));
    }

    /**
     * parseEnvValue() must convert a numeric float string to float.
     *
     * This covers the `strpos($trimmedValue, '.') !== false` → `(float)` branch.
     */
    public function testParseEnvValueConvertsFloatStringToFloat(): void
    {
        // Assert
        $this->assertSame(3.14, parseEnvValue('3.14'));
        $this->assertSame(0.5,  parseEnvValue('0.5'));
    }

    /**
     * parseEnvValue() must return non-scalar values as-is.
     *
     * This covers the `if (!is_string($value)) return $value` guard (line ~52).
     */
    public function testParseEnvValueReturnsNonStringValueUnchanged(): void
    {
        // Assert
        $this->assertSame(123,   parseEnvValue(123));
        $this->assertTrue(parseEnvValue(true));
        $this->assertNull(parseEnvValue(null));
    }

    /**
     * parseEnvValue() must return a plain unquoted string as-is.
     *
     * This covers the final `return $trimmedValue` fallback (line ~82).
     */
    public function testParseEnvValueReturnsPlainStringUnchanged(): void
    {
        // Act
        $result = parseEnvValue('  my-string  ');

        // Assert — whitespace is trimmed, value unchanged
        $this->assertSame('my-string', $result);
    }

    // ── envvar() ──────────────────────────────────────────────────────────────

    /**
     * envvar() must return the value from getenv() when the variable is set there.
     *
     * This covers the `if ($value !== false)` branch (line ~36).
     */
    public function testEnvvarReadsFromGetenv(): void
    {
        // Arrange — set a temporary env var
        $key = 'PRAMNOS_TEST_VAR_' . bin2hex(random_bytes(4));
        putenv("$key=hello_from_getenv");

        try {
            // Act
            $result = envvar($key, 'fallback');

            // Assert
            $this->assertSame('hello_from_getenv', $result);
        } finally {
            // Clean up
            putenv($key);
        }
    }

    /**
     * envvar() must return the default when the variable is not set anywhere.
     *
     * This covers the final `return $defaultReturn` fallback.
     */
    public function testEnvvarReturnsDefaultWhenVarMissing(): void
    {
        // Arrange — a key that certainly doesn't exist
        $key = 'PRAMNOS_TEST_MISSING_' . bin2hex(random_bytes(4));

        // Act
        $result = envvar($key, 'default_val');

        // Assert
        $this->assertSame('default_val', $result);
    }

    /**
     * envvar() must read from $_ENV when getenv() returns false.
     *
     * This covers the `array_key_exists($field, $_ENV)` branch (line ~40).
     */
    public function testEnvvarReadsFromEnvSuperGlobal(): void
    {
        // Arrange — inject into $_ENV only (not putenv)
        $key = 'PRAMNOS_TEST_ENV_' . bin2hex(random_bytes(4));
        $_ENV[$key] = 'from_env_superglobal';

        try {
            // Act
            $result = envvar($key, 'fallback');

            // Assert
            $this->assertSame('from_env_superglobal', $result);
        } finally {
            unset($_ENV[$key]);
        }
    }

    /**
     * envvar() must read from $_SERVER when getenv() and $_ENV both miss.
     *
     * This covers the `array_key_exists($field, $_SERVER)` branch (line ~44).
     */
    public function testEnvvarReadsFromServerSuperGlobal(): void
    {
        // Arrange — inject only into $_SERVER
        $key = 'PRAMNOS_TEST_SERVER_' . bin2hex(random_bytes(4));
        $_SERVER[$key] = 'from_server_superglobal';

        try {
            // Act — getenv() misses, $_ENV misses, $_SERVER hits
            $result = envvar($key, 'fallback');

            // Assert
            $this->assertSame('from_server_superglobal', $result);
        } finally {
            unset($_SERVER[$key]);
        }
    }

    // ── e() ───────────────────────────────────────────────────────────────────

    /**
     * e() must HTML-escape a string containing special characters.
     *
     * This covers the `htmlspecialchars(...)` call in e().
     */
    public function testEEscapesHtmlSpecialChars(): void
    {
        // Assert
        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;',
            e('<script>alert(1)</script>'),
            'HTML tags must be escaped');

        $this->assertSame('&amp;', e('&'), '"&" must become "&amp;"');
        $this->assertSame('&quot;', e('"'), '"<" must become "&quot;"');
        $this->assertSame('&#039;', e("'"), '"\'\" must become "&#039;"');
    }

    /**
     * e() must return an empty string for null.
     *
     * This covers the `if ($value === null || $value === false) return ''` guard.
     */
    public function testEReturnsEmptyStringForNullAndFalse(): void
    {
        // Assert
        $this->assertSame('', e(null),  'null must return ""');
        $this->assertSame('', e(false), 'false must return ""');
    }

    /**
     * e() must convert a non-string scalar (int, float) to string before encoding.
     *
     * This covers the `(string) $value` cast.
     */
    public function testEConvertsScalarsToString(): void
    {
        // Assert
        $this->assertSame('42',   e(42));
        $this->assertSame('3.14', e(3.14));
    }

    /**
     * e() must pass a plain ASCII string through unchanged.
     *
     * No special characters to escape → identical output.
     */
    public function testEPassesThroughSafeStrings(): void
    {
        // Assert
        $this->assertSame('hello world', e('hello world'));
    }
}
