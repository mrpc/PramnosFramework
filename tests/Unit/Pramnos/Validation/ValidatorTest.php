<?php

namespace Pramnos\Tests\Unit\Validation;

use PHPUnit\Framework\TestCase;
use Pramnos\Validation\Validator;
use Pramnos\Validation\ValidationException;
use Pramnos\Validation\RuleInterface;
use Pramnos\Http\Session;

#[\PHPUnit\Framework\Attributes\CoversClass(Validator::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\Pramnos\General\Validator::class)]
#[\PHPUnit\Framework\Attributes\IgnoreDeprecations]
class ValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        // Mock session for CSRF testing
        $_SESSION = [];
        Session::getInstance()->start();
    }

    /**
     * Test the 'required' validation rule
     */
    public function testBasicRequiredRule()
    {
        $data = ['name' => 'John'];
        $rules = ['name' => 'required'];
        
        $validated = Validator::validate($data, $rules);
        $this->assertEquals($data, $validated);
        
        $this->expectException(ValidationException::class);
        Validator::validate([], $rules);
    }

    /**
     * Test 'string', 'min', and 'max' validation rules
     */
    public function testStringAndMinMaxRules()
    {
        $data = ['name' => 'abc'];
        $rules = ['name' => 'string|min:2|max:5'];
        
        $validated = Validator::validate($data, $rules);
        $this->assertEquals($data, $validated);
        
        $this->expectException(ValidationException::class);
        Validator::validate(['name' => 'a'], $rules);
    }

    /**
     * Test successful CSRF token validation
     */
    public function testCsrfRuleSuccess()
    {
        $session = Session::getInstance();
        $token = $session->getToken();
        $fingerprint = $session->getFingerprint();
        
        $data = [$token => $fingerprint, 'name' => 'John'];
        $rules = [
            $token => 'csrf',
            'name' => 'required'
        ];
        
        $validated = Validator::validate($data, $rules);
        $this->assertEquals($data, $validated);
    }

    /**
     * Test CSRF token validation failures
     */
    public function testCsrfRuleFailure()
    {
        $session = Session::getInstance();
        $token = $session->getToken();
        
        $rules = [$token => 'csrf'];
        
        // Wrong value (the old value '1' is now invalid)
        try {
            Validator::validate([$token => '1'], $rules);
            $this->fail('Expected ValidationException was not thrown');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($token, $e->errors());
        }
        
        // Wrong field name (using a different token)
        try {
            Validator::validate(['wrong_token' => $session->getFingerprint()], $rules);
            $this->fail('Expected ValidationException was not thrown');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($token, $e->errors());
        }
    }

    /**
     * Test the 'url' validation rule
     */
    public function testUrlRule()
    {
        $rules = ['website' => 'url'];
        
        $validated = Validator::validate(['website' => 'https://google.com'], $rules);
        $this->assertEquals('https://google.com', $validated['website']);
        
        $validated = Validator::validate(['website' => 'google.com'], $rules);
        $this->assertEquals('http://google.com', $validated['website']);
        
        $this->expectException(ValidationException::class);
        Validator::validate(['website' => 'not-a-url'], $rules);
    }

    /**
     * Test the 'json' validation rule
     */
    public function testJsonRule()
    {
        $rules = ['data' => 'json'];
        
        $json = json_encode(['foo' => 'bar']);
        $validated = Validator::validate(['data' => $json], $rules);
        $this->assertEquals($json, $validated['data']);
        
        $this->expectException(ValidationException::class);
        Validator::validate(['data' => 'not-json'], $rules);
    }

    /**
     * Test legacy static helper methods directly
     */
    public function testLegacyMethods()
    {
        $this->assertEquals('test@example.com', Validator::checkEmail(' TEST@example.com '));
        $this->assertFalse(Validator::checkEmail('invalid-email'));
        
        $this->assertTrue(Validator::isJson('{"a":1}'));
        $this->assertFalse(Validator::isJson('not json'));
        
        $this->assertEquals('http://google.com', Validator::checkLink('google.com'));
        $this->assertEquals('https://google.com', Validator::checkLink('https://google.com'));
        $this->assertFalse(Validator::checkLink('invalid'));
    }

    /**
     * Test compatibility with the legacy Validator class wrapper
     */
    public function testLegacyWrapper()
    {
        // Testing that the legacy namespace class still works (delegating to the new one)
        $this->assertEquals('test@example.com', \Pramnos\General\Validator::checkEmail('test@example.com'));
        $this->assertTrue(\Pramnos\General\Validator::isJson('[]'));
        $this->assertEquals('http://google.com', \Pramnos\General\Validator::checkLink('google.com'));
        
        // Testing singleton compatibility
        $instance = \Pramnos\General\Validator::getInstance();
        $this->assertInstanceOf(\Pramnos\Validation\Validator::class, $instance);
        $this->assertInstanceOf(\Pramnos\General\Validator::class, $instance);
    }

    /**
     * Test that using the legacy class triggers a deprecation notice
     */
    public function testDeprecationNotice()
    {
        $deprecatedMessage = '';
        set_error_handler(function($errno, $errstr) use (&$deprecatedMessage) {
            $deprecatedMessage = $errstr;
            return true;
        }, E_USER_DEPRECATED);

        \Pramnos\General\Validator::checkEmail('test@example.com');
        restore_error_handler();

        $this->assertStringContainsString('Pramnos\General\Validator is deprecated', $deprecatedMessage);
    }

    /**
     * Test the 'between' validation rule for numbers and strings
     */
    public function testBetweenRule()
    {
        $rules = ['age' => 'between:18,99', 'name' => 'between:3,10'];
        
        // Success
        $validated = Validator::validate(['age' => 25, 'name' => 'John'], $rules);
        $this->assertEquals(25, $validated['age']);
        
        // Failure - Numeric
        try {
            Validator::validate(['age' => 17], ['age' => 'between:18,99']);
            $this->fail('Expected ValidationException was not thrown');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('age', $e->errors());
        }
        
        // Failure - String
        try {
            Validator::validate(['name' => 'Jo'], ['name' => 'between:3,10']);
            $this->fail('Expected ValidationException was not thrown');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name', $e->errors());
        }

        // Invalid parameters (should fail validation gracefully)
        try {
            Validator::validate(['v' => 10], ['v' => 'between:10']);
            $this->fail('Expected ValidationException was not thrown');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('v', $e->errors());
        }
    }

    /**
     * Test coverage for various basic validation rules (boolean, numeric, integer, in)
     */
    public function testRulesCoverage()
    {
        $rules = [
            'is_valid' => 'boolean',
            'score'    => 'numeric',
            'count'    => 'integer',
            'category' => 'in:a,b,c'
        ];

        $data = [
            'is_valid' => '1',
            'score'    => '10.5',
            'count'    => '10',
            'category' => 'b'
        ];

        $validated = Validator::validate($data, $rules);
        $this->assertEquals('1', $validated['is_valid']);
        $this->assertEquals('10.5', $validated['score']);
        
        // Failure cases
        try {
            Validator::validate(['score' => 'not-numeric'], ['score' => 'numeric']);
            $this->fail();
        } catch (ValidationException $e) {}

        try {
            Validator::validate(['count' => 'not-int'], ['count' => 'integer']);
            $this->fail();
        } catch (ValidationException $e) {}

        try {
            Validator::validate(['is_valid' => 'not-bool'], ['is_valid' => 'boolean']);
            $this->fail();
        } catch (ValidationException $e) {}

        try {
            Validator::validate(['category' => 'd'], ['category' => 'in:a,b,c']);
            $this->fail();
        } catch (ValidationException $e) {}
    }

    /**
     * Test 'min' and 'max' rules with both numbers and arrays
     */
    public function testMinMaxWithArraysAndNumbers()
    {
        // Numeric
        $v1 = Validator::validate(['v' => 10], ['v' => 'min:5']);
        $this->assertEquals(10, $v1['v']);
        
        $v2 = Validator::validate(['v' => 5], ['v' => 'max:10']);
        $this->assertEquals(5, $v2['v']);
        
        // Array
        $v3 = Validator::validate(['v' => [1,2,3]], ['v' => 'min:2']);
        $this->assertCount(3, $v3['v']);
        
        $v4 = Validator::validate(['v' => [1]], ['v' => 'max:2']);
        $this->assertCount(1, $v4['v']);

        // Failures
        try { Validator::validate(['v' => 4], ['v' => 'min:5']); $this->fail(); } catch(ValidationException $e){}
        try { Validator::validate(['v' => 11], ['v' => 'max:10']); $this->fail(); } catch(ValidationException $e){}
        try { Validator::validate(['v' => [1]], ['v' => 'min:2']); $this->fail(); } catch(ValidationException $e){}
        try { Validator::validate(['v' => [1,2,3]], ['v' => 'max:2']); $this->fail(); } catch(ValidationException $e){}
        
        // Invalid non-numeric parameters for min/max
        try { Validator::validate(['v' => 10], ['v' => 'min:abc']); $this->fail(); } catch(ValidationException $e){ $this->assertArrayHasKey('v', $e->errors()); }
        try { Validator::validate(['v' => 1], ['v' => 'max:abc']); $this->fail(); } catch(ValidationException $e){ $this->assertArrayHasKey('v', $e->errors()); }
        
        // Unsupported types for min/max
        try { Validator::validate(['v' => null], ['v' => 'min:5']); $this->fail(); } catch(ValidationException $e){}
    }

    /**
     * Test custom validation messages and attribute names
     */
    public function testCustomMessagesAndAttributes()
    {
        $rules = ['email_address' => 'required|email'];
        $messages = ['email_address.required' => 'We need your :attribute!'];
        $attributes = ['email_address' => 'official email'];

        try {
            Validator::validate([], $rules, $messages, $attributes);
        } catch (ValidationException $e) {
            $this->assertEquals('We need your official email!', $e->errors()['email_address'][0]);
        }

        // Generic rule message
        try {
            Validator::validate(['f' => 'not-email'], ['f' => 'email'], ['email' => 'BAD EMAIL']);
        } catch (ValidationException $e) {
            $this->assertEquals('BAD EMAIL', $e->errors()['f'][0]);
        }
    }

    /**
     * Test the 'nullable' rule behavior
     */
    public function testNullableBehavior()
    {
        $rules = ['bio' => 'nullable|string|min:10'];
        
        // Exists but null/empty
        $validated = Validator::validate(['bio' => ''], $rules);
        $this->assertEquals('', $validated['bio']);
        
        // Exists and valid
        $validated = Validator::validate(['bio' => 'A long enough bio string'], $rules);
        $this->assertEquals('A long enough bio string', $validated['bio']);
    }

    /**
     * Test behavior when an unknown validation rule is encountered
     */
    public function testUnknownRule()
    {
        $this->expectException(\InvalidArgumentException::class);
        Validator::validate(['f' => 'v'], ['f' => 'unknown_rule']);
    }

    /**
     * Test various edge cases for direct helper methods
     */
    public function testHelperEdgeCases()
    {
        // checkEmail sanitization (filter_var)
        $this->assertEquals('test@example.com', Validator::checkEmail('test@example.com '));
        
        // isJson
        $this->assertTrue(Validator::isJson('{"key":"value"}'));
        $this->assertFalse(Validator::isJson('invalid'));

        // checkLink edge cases
        $this->assertFalse(Validator::checkLink('google')); // No dot
        $this->assertFalse(Validator::checkLink('invalid-url-$$$'));
        
        // getInstance
        $i1 = Validator::getInstance();
        $i2 = Validator::getInstance();
        $this->assertSame($i1, $i2);
    }
    /**
     * Test that fields present in data but empty trigger required validation
     */
    public function testRequiredFieldExistsButIsEmpty()
    {
        $rules = ['name' => 'required'];
        
        $this->expectException(ValidationException::class);
        Validator::validate(['name' => ''], $rules);
    }

    /**
     * Test that rules can be passed as an array
     */
    public function testRulesAsArray()
    {
        $data = ['name' => 'John'];
        $rules = ['name' => ['required', 'string']];
        
        $validated = Validator::validate($data, $rules);
        $this->assertEquals($data, $validated);
    }

    /**
     * Test validation behavior with unsupported data types
     */
    public function testUnsupportedTypesInMinMax()
    {
        // min with unsupported type
        try {
            Validator::validate(['v' => null], ['v' => 'min:5']);
            $this->fail('Expected ValidationException was not thrown');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('v', $e->errors());
        }

        // max with unsupported type
        try {
            Validator::validate(['v' => (object)[]], ['v' => 'max:5']);
            $this->fail('Expected ValidationException was not thrown');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('v', $e->errors());
        }
    }

    /**
     * Test that non-implicit rules are skipped when fields are missing
     */
    public function testSkippingNonImplicitRulesForMissingFields()
    {
        $rules = ['email' => 'email'];
        $data = [];
        
        $validated = Validator::validate($data, $rules);
        $this->assertEmpty($validated);
    }

    /**
     * Test required rule processing within the main rule loop
     */
    public function testExplicitRequiredInsideLoop()
    {
        $rules = ['name' => 'required|string'];
        $data = ['name' => 'John'];
        
        $validated = Validator::validate($data, $rules);
        $this->assertEquals('John', $validated['name']);
    }

    /**
     * Test parsing of rules with extra whitespace or empty segments
     */
    public function testParseRulesWithPipeGaps()
    {
        $rules = ['name' => 'required| |string'];
        $data = ['name' => 'John'];
        
        $validated = Validator::validate($data, $rules);
        $this->assertEquals('John', $validated['name']);
    }

    /**
     * Test missing fields that contain a mix of implicit and regular rules
     */
    public function testMissingFieldWithImplicitAndNonImplicitRules()
    {
        $session = Session::getInstance();
        $token = $session->getToken();

        // Field is missing, but has both an implicit rule (csrf) and a non-implicit one (string)
        $rules = [$token => 'csrf|string'];
        $data = [];

        try {
            Validator::validate($data, $rules);
            $this->fail('Expected ValidationException was not thrown');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($token, $e->errors());
            // It should fail on CSRF, and skip the 'string' rule inside the loop
        }
    }

    // =========================================================================
    // New common rules
    // =========================================================================

    /**
     * 'alpha' accepts only Unicode letters; rejects digits, spaces, and symbols.
     *
     * This validates that the regex uses the \pL\pM Unicode categories, not just
     * the ASCII [a-z] range — so accented characters are allowed.
     */
    public function testAlphaRule(): void
    {
        // Arrange
        $rules = ['name' => 'alpha'];

        // Assert: plain ASCII letters pass
        $this->assertSame(['name' => 'John'], Validator::validate(['name' => 'John'], $rules));

        // Assert: accented letter passes (Unicode \pL)
        $this->assertSame(['name' => 'Ελένη'], Validator::validate(['name' => 'Ελένη'], $rules));

        // Assert: digit causes failure
        try {
            Validator::validate(['name' => 'John2'], $rules);
            $this->fail('Expected ValidationException for digit in alpha field');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name', $e->errors());
        }

        // Assert: space causes failure
        try {
            Validator::validate(['name' => 'John Doe'], $rules);
            $this->fail('Expected ValidationException for space in alpha field');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name', $e->errors());
        }
    }

    /**
     * 'alpha_num' accepts letters and digits; rejects spaces and symbols.
     *
     * Cross-database slugs and usernames often need exactly this combination.
     */
    public function testAlphaNumRule(): void
    {
        // Arrange
        $rules = ['slug' => 'alpha_num'];

        // Assert: letters + digits pass
        $this->assertSame(['slug' => 'abc123'], Validator::validate(['slug' => 'abc123'], $rules));

        // Assert: hyphen causes failure
        try {
            Validator::validate(['slug' => 'abc-123'], $rules);
            $this->fail('Expected ValidationException for hyphen in alpha_num field');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('slug', $e->errors());
        }
    }

    /**
     * 'digits:n' requires exactly n digit characters; rejects letters, floats, and wrong lengths.
     *
     * Used for OTP codes, PIN numbers, and fixed-length numeric identifiers.
     */
    public function testDigitsRule(): void
    {
        // Arrange
        $rules = ['code' => 'digits:6'];

        // Assert: exactly 6 digits pass
        $this->assertSame(['code' => '123456'], Validator::validate(['code' => '123456'], $rules));

        // Assert: 5 digits fail (wrong length)
        try {
            Validator::validate(['code' => '12345'], $rules);
            $this->fail('Expected ValidationException for wrong digit count');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('code', $e->errors());
        }

        // Assert: contains letter fails
        try {
            Validator::validate(['code' => '12345a'], $rules);
            $this->fail('Expected ValidationException for non-digit character');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('code', $e->errors());
        }
    }

    /**
     * 'regex' validates against a PCRE pattern supplied as a parameter.
     *
     * Useful for custom formats (e.g. Greek postal codes, product codes)
     * that do not map to a built-in rule.
     */
    public function testRegexRule(): void
    {
        // Arrange: Greek postal code — 5 digits
        $rules = ['postcode' => 'regex:/^\d{5}$/'];

        // Assert: valid 5-digit code passes
        $this->assertSame(
            ['postcode' => '10431'],
            Validator::validate(['postcode' => '10431'], $rules)
        );

        // Assert: 4 digits fail
        try {
            Validator::validate(['postcode' => '1043'], $rules);
            $this->fail('Expected ValidationException for short postcode');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('postcode', $e->errors());
        }
    }

    /**
     * 'ip' accepts valid IPv4 and IPv6 addresses; rejects hostnames and malformed strings.
     */
    public function testIpRule(): void
    {
        // Arrange
        $rules = ['addr' => 'ip'];

        // Act + Assert: IPv4 passes
        $this->assertSame(
            ['addr' => '192.168.1.1'],
            Validator::validate(['addr' => '192.168.1.1'], $rules)
        );

        // Act + Assert: IPv6 passes
        $this->assertSame(
            ['addr' => '::1'],
            Validator::validate(['addr' => '::1'], $rules)
        );

        // Assert: hostname fails
        try {
            Validator::validate(['addr' => 'localhost'], $rules);
            $this->fail('Expected ValidationException for hostname');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('addr', $e->errors());
        }
    }

    /**
     * 'uuid' accepts standard UUID v4 format (case-insensitive); rejects non-UUID strings.
     *
     * UUIDs are commonly used as primary keys in distributed systems.
     */
    public function testUuidRule(): void
    {
        // Arrange
        $rules = ['id' => 'uuid'];
        $valid = 'f47ac10b-58cc-4372-a567-0e02b2c3d479';

        // Assert: lowercase passes
        $this->assertSame(['id' => $valid], Validator::validate(['id' => $valid], $rules));

        // Assert: uppercase also passes (case-insensitive)
        $upper = strtoupper($valid);
        $this->assertSame(['id' => $upper], Validator::validate(['id' => $upper], $rules));

        // Assert: plain integer fails
        try {
            Validator::validate(['id' => '12345'], $rules);
            $this->fail('Expected ValidationException for non-UUID value');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('id', $e->errors());
        }
    }

    /**
     * 'not_in' rejects values that appear in the supplied list.
     *
     * This is the complement of 'in' — useful for blocklists (reserved words,
     * banned usernames, forbidden status transitions).
     */
    public function testNotInRule(): void
    {
        // Arrange
        $rules = ['role' => 'not_in:admin,superuser'];

        // Assert: unlisted value passes
        $this->assertSame(
            ['role' => 'editor'],
            Validator::validate(['role' => 'editor'], $rules)
        );

        // Assert: listed value fails
        try {
            Validator::validate(['role' => 'admin'], $rules);
            $this->fail('Expected ValidationException for blocked value');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('role', $e->errors());
        }
    }

    /**
     * 'starts_with' and 'ends_with' validate string prefixes and suffixes.
     *
     * Used for protocol prefixes (https://), file extensions, namespace prefixes.
     */
    public function testStartsWithAndEndsWithRules(): void
    {
        // Arrange
        $startRules = ['url' => 'starts_with:https://'];
        $endRules   = ['file' => 'ends_with:.pdf'];

        // Assert: correct prefix passes
        $this->assertSame(
            ['url' => 'https://example.com'],
            Validator::validate(['url' => 'https://example.com'], $startRules)
        );

        // Assert: wrong prefix fails
        try {
            Validator::validate(['url' => 'http://example.com'], $startRules);
            $this->fail('Expected ValidationException for wrong prefix');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('url', $e->errors());
        }

        // Assert: correct suffix passes
        $this->assertSame(
            ['file' => 'report.pdf'],
            Validator::validate(['file' => 'report.pdf'], $endRules)
        );

        // Assert: wrong suffix fails
        try {
            Validator::validate(['file' => 'report.docx'], $endRules);
            $this->fail('Expected ValidationException for wrong suffix');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('file', $e->errors());
        }
    }

    /**
     * 'array' verifies the value is a PHP array.
     *
     * Essential before iterating over user input that is expected to be
     * an array (e.g. multi-select form fields).
     */
    public function testArrayRule(): void
    {
        // Arrange
        $rules = ['tags' => 'array'];

        // Assert: array passes
        $this->assertSame(
            ['tags' => ['php', 'mysql']],
            Validator::validate(['tags' => ['php', 'mysql']], $rules)
        );

        // Assert: string fails
        try {
            Validator::validate(['tags' => 'php,mysql'], $rules);
            $this->fail('Expected ValidationException for non-array value');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('tags', $e->errors());
        }
    }

    /**
     * 'size' requires an exact length for strings, exact count for arrays,
     * and exact numeric equality for numbers.
     *
     * Used for fixed-format codes (e.g. country code = 2 chars, PIN = 4 digits).
     */
    public function testSizeRule(): void
    {
        // Assert: 2-char country code passes
        $this->assertSame(
            ['country' => 'GR'],
            Validator::validate(['country' => 'GR'], ['country' => 'size:2'])
        );

        // Assert: 3-char string fails
        try {
            Validator::validate(['country' => 'GRC'], ['country' => 'size:2']);
            $this->fail('Expected ValidationException for wrong string size');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('country', $e->errors());
        }

        // Assert: array of exactly 3 items passes
        $this->assertSame(
            ['items' => [1, 2, 3]],
            Validator::validate(['items' => [1, 2, 3]], ['items' => 'size:3'])
        );

        // Assert: numeric exact match passes
        $this->assertSame(
            ['score' => 100],
            Validator::validate(['score' => 100], ['score' => 'size:100'])
        );
    }

    /**
     * 'confirmed' requires a matching <field>_confirmation key in the same data.
     *
     * The canonical use case is password confirmation fields; verifying that
     * both values match before hashing and storing the password.
     */
    public function testConfirmedRule(): void
    {
        // Arrange: both fields present and matching
        $data  = ['password' => 'secret', 'password_confirmation' => 'secret'];
        $rules = ['password' => 'confirmed'];

        // Assert: matching confirmation passes
        $this->assertSame('secret', Validator::validate($data, $rules)['password']);

        // Assert: mismatched confirmation fails
        try {
            Validator::validate(
                ['password' => 'secret', 'password_confirmation' => 'wrong'],
                $rules
            );
            $this->fail('Expected ValidationException for mismatched confirmation');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('password', $e->errors());
        }

        // Assert: missing confirmation field fails
        try {
            Validator::validate(['password' => 'secret'], $rules);
            $this->fail('Expected ValidationException for missing confirmation field');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('password', $e->errors());
        }
    }

    // =========================================================================
    // Date rules
    // =========================================================================

    /**
     * 'date' accepts any string that PHP's strtotime() can parse.
     *
     * Dates arrive as strings from HTTP requests; this rule confirms they are
     * a recognisable date before further processing.
     */
    public function testDateRule(): void
    {
        // Arrange
        $rules = ['dob' => 'date'];

        // Assert: ISO date passes
        $this->assertSame(
            ['dob' => '1990-05-20'],
            Validator::validate(['dob' => '1990-05-20'], $rules)
        );

        // Assert: natural language date passes (strtotime understands it)
        $this->assertSame(
            ['dob' => 'next Monday'],
            Validator::validate(['dob' => 'next Monday'], $rules)
        );

        // Assert: random string fails
        try {
            Validator::validate(['dob' => 'not-a-date'], $rules);
            $this->fail('Expected ValidationException for non-date string');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('dob', $e->errors());
        }
    }

    /**
     * 'date_format' requires the value to match exactly the given PHP date format.
     *
     * More strict than 'date': '2025-5-1' passes 'date' but fails 'date_format:Y-m-d'
     * because the day and month are not zero-padded.
     */
    public function testDateFormatRule(): void
    {
        // Arrange
        $rules = ['ts' => 'date_format:Y-m-d'];

        // Assert: zero-padded ISO date passes
        $this->assertSame(
            ['ts' => '2025-05-01'],
            Validator::validate(['ts' => '2025-05-01'], $rules)
        );

        // Assert: non-padded date fails the strict format check
        try {
            Validator::validate(['ts' => '2025-5-1'], $rules);
            $this->fail('Expected ValidationException for non-zero-padded date');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('ts', $e->errors());
        }
    }

    /**
     * 'before' and 'after' compare the value date against a threshold date.
     *
     * 'before_or_equal' and 'after_or_equal' are the inclusive variants.
     * These are used for booking windows, expiry dates, and age checks.
     */
    public function testBeforeAndAfterRules(): void
    {
        // 'before': value must be before 2025-01-01
        $this->assertSame(
            ['date' => '2024-12-31'],
            Validator::validate(['date' => '2024-12-31'], ['date' => 'before:2025-01-01'])
        );

        try {
            Validator::validate(['date' => '2025-01-01'], ['date' => 'before:2025-01-01']);
            $this->fail('Expected ValidationException: equal date must fail strict before');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('date', $e->errors());
        }

        // 'before_or_equal': equal date must pass
        $this->assertSame(
            ['date' => '2025-01-01'],
            Validator::validate(['date' => '2025-01-01'], ['date' => 'before_or_equal:2025-01-01'])
        );

        // 'after': value must be after 2024-01-01
        $this->assertSame(
            ['date' => '2024-06-15'],
            Validator::validate(['date' => '2024-06-15'], ['date' => 'after:2024-01-01'])
        );

        try {
            Validator::validate(['date' => '2024-01-01'], ['date' => 'after:2024-01-01']);
            $this->fail('Expected ValidationException: equal date must fail strict after');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('date', $e->errors());
        }

        // 'after_or_equal': equal date must pass
        $this->assertSame(
            ['date' => '2024-01-01'],
            Validator::validate(['date' => '2024-01-01'], ['date' => 'after_or_equal:2024-01-01'])
        );
    }

    // =========================================================================
    // Conditional rules
    // =========================================================================

    /**
     * 'sometimes' skips all rules (including 'required') when the field is absent.
     *
     * Useful for PATCH endpoints where only submitted fields should be validated;
     * 'sometimes|required' means "if present, must be non-empty".
     */
    public function testSometimesRule(): void
    {
        // Arrange: field is absent
        $rules = ['phone' => 'sometimes|required|string'];

        // Assert: absent field passes (not validated at all)
        $validated = Validator::validate([], $rules);
        $this->assertArrayNotHasKey('phone', $validated);

        // Assert: present + valid passes
        $validated = Validator::validate(['phone' => '6901234567'], $rules);
        $this->assertSame('6901234567', $validated['phone']);

        // Assert: present + empty fails required
        try {
            Validator::validate(['phone' => ''], $rules);
            $this->fail('Expected ValidationException: present empty field must fail required');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('phone', $e->errors());
        }
    }

    /**
     * 'required_if:other,value' makes the field required only when another field
     * equals a specific value.
     *
     * Example: billing address is required only when payment_method = 'invoice'.
     */
    public function testRequiredIfRule(): void
    {
        // Arrange
        $rules = [
            'payment_method'  => 'required',
            'billing_address' => 'required_if:payment_method,invoice',
        ];

        // Assert: billing_address is absent but payment_method = 'card' → passes
        $validated = Validator::validate(
            ['payment_method' => 'card'],
            $rules
        );
        $this->assertArrayNotHasKey('billing_address', $validated);

        // Assert: billing_address is absent and payment_method = 'invoice' → fails
        try {
            Validator::validate(['payment_method' => 'invoice'], $rules);
            $this->fail('Expected ValidationException: billing_address required when payment=invoice');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('billing_address', $e->errors());
        }

        // Assert: billing_address present + payment=invoice → passes
        $validated = Validator::validate(
            ['payment_method' => 'invoice', 'billing_address' => 'Main St 1'],
            $rules
        );
        $this->assertSame('Main St 1', $validated['billing_address']);
    }

    /**
     * 'required_unless:other,value' makes the field required unless another field
     * equals a specific value.
     *
     * Example: tax_id is required unless the user chose 'individual' account type.
     */
    public function testRequiredUnlessRule(): void
    {
        // Arrange
        $rules = [
            'account_type' => 'required',
            'tax_id'       => 'required_unless:account_type,individual',
        ];

        // Assert: account_type = 'individual', tax_id absent → passes
        $validated = Validator::validate(['account_type' => 'individual'], $rules);
        $this->assertArrayNotHasKey('tax_id', $validated);

        // Assert: account_type = 'company', tax_id absent → fails
        try {
            Validator::validate(['account_type' => 'company'], $rules);
            $this->fail('Expected ValidationException: tax_id required for company accounts');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('tax_id', $e->errors());
        }

        // Assert: account_type = 'company', tax_id present → passes
        $validated = Validator::validate(
            ['account_type' => 'company', 'tax_id' => 'EL123456789'],
            $rules
        );
        $this->assertSame('EL123456789', $validated['tax_id']);
    }

    /**
     * 'required_with:field1,field2' makes the field required if ANY of the listed
     * fields are present and non-empty.
     *
     * Example: if a user supplies a street address, the city must also be supplied.
     */
    public function testRequiredWithRule(): void
    {
        // Arrange
        $rules = [
            'street' => 'sometimes|string',
            'city'   => 'required_with:street',
        ];

        // Assert: neither field present → passes
        $validated = Validator::validate([], $rules);
        $this->assertArrayNotHasKey('city', $validated);

        // Assert: street present, city absent → fails
        try {
            Validator::validate(['street' => 'Main St 1'], $rules);
            $this->fail('Expected ValidationException: city required when street is provided');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('city', $e->errors());
        }

        // Assert: both present → passes
        $validated = Validator::validate(
            ['street' => 'Main St 1', 'city' => 'Athens'],
            $rules
        );
        $this->assertSame('Athens', $validated['city']);
    }

    /**
     * 'required_without:field1,field2' makes the field required if ANY of the
     * listed fields are absent or empty.
     *
     * Example: either email or phone must be supplied — if email is absent, phone
     * becomes required.
     */
    public function testRequiredWithoutRule(): void
    {
        // Arrange
        $rules = [
            'email' => 'sometimes|email',
            'phone' => 'required_without:email',
        ];

        // Assert: email absent, phone absent → phone fails
        try {
            Validator::validate([], $rules);
            $this->fail('Expected ValidationException: phone required when email is absent');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('phone', $e->errors());
        }

        // Assert: email present → phone not required
        $validated = Validator::validate(['email' => 'user@example.com'], $rules);
        $this->assertArrayNotHasKey('phone', $validated);

        // Assert: email absent, phone present → passes
        $validated = Validator::validate(['phone' => '6901234567'], $rules);
        $this->assertSame('6901234567', $validated['phone']);
    }

    // =========================================================================
    // Custom rules
    // =========================================================================

    /**
     * Validator::extend() registers a callable that receives (attribute, value, parameters).
     *
     * Custom callables allow one-off rules to be defined inline without creating
     * a full RuleInterface class — useful in tests and application bootstrapping.
     */
    public function testExtendWithCallable(): void
    {
        // Arrange: register a rule that rejects values starting with an underscore
        Validator::extend('no_underscore', function (string $attr, mixed $val, array $params): bool {
            return !str_starts_with((string) $val, '_');
        });

        // Assert: valid value passes
        $this->assertSame(
            ['username' => 'johndoe'],
            Validator::validate(['username' => 'johndoe'], ['username' => 'no_underscore'])
        );

        // Assert: underscore-prefixed value fails
        try {
            Validator::validate(['username' => '_admin'], ['username' => 'no_underscore']);
            $this->fail('Expected ValidationException for underscore-prefixed username');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('username', $e->errors());
        }
    }

    /**
     * Validator::extend() also accepts a RuleInterface object, which controls both
     * the passes() logic and the error message.
     *
     * This is the preferred pattern for reusable domain-specific rules.
     */
    public function testExtendWithRuleInterface(): void
    {
        // Arrange: anonymous RuleInterface that rejects negative numbers
        $positiveRule = new class implements RuleInterface {
            public function passes(string $attribute, mixed $value): bool
            {
                return is_numeric($value) && (float) $value > 0;
            }
            public function message(): string
            {
                return 'The :attribute must be a positive number.';
            }
        };

        Validator::extend('positive', $positiveRule);

        // Assert: positive value passes
        $this->assertSame(
            ['amount' => 42],
            Validator::validate(['amount' => 42], ['amount' => 'positive'])
        );

        // Assert: zero fails
        try {
            Validator::validate(['amount' => 0], ['amount' => 'positive']);
            $this->fail('Expected ValidationException for zero');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }

        // Assert: negative fails
        try {
            Validator::validate(['amount' => -5], ['amount' => 'positive']);
            $this->fail('Expected ValidationException for negative number');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }
    }

    /**
     * resolveRequired() skips RuleInterface entries in the parsedRules array without crashing.
     *
     * When a rules array mixes string rules (like required_if) with inline RuleInterface
     * objects, resolveRequired() must iterate safely past the objects. This covers the
     * `continue` guard at the top of the resolveRequired() loop (line 197).
     */
    public function testResolveRequiredSkipsRuleInterfaceObjects(): void
    {
        // Arrange: a RuleInterface object combined with a conditional required rule
        $alwaysPass = new class implements RuleInterface {
            public function passes(string $attribute, mixed $value): bool { return true; }
            public function message(): string { return 'fail'; }
        };

        $rules = [
            'status' => 'required',
            // 'note' is required_if:status,active AND has an inline rule object
            'note'   => ['required_if:status,active', $alwaysPass],
        ];

        // Assert: resolveRequired() correctly resolves required_if without choking on the object
        try {
            // status=active, note absent → required_if fires
            Validator::validate(['status' => 'active'], $rules);
            $this->fail('Expected ValidationException: note required when status=active');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('note', $e->errors());
        }

        // Assert: status=inactive → note not required, RuleInterface object runs on present value
        $validated = Validator::validate(['status' => 'inactive', 'note' => 'ok'], $rules);
        $this->assertSame('ok', $validated['note']);
    }

    /**
     * When a field is absent and its rules array contains both an implicit rule (csrf) and
     * a RuleInterface object, the implicit-rule scan must skip the RuleInterface safely.
     *
     * This covers the `continue` guard inside the mustRun loop (line 113).
     */
    public function testMissingFieldWithImplicitRuleAndInlineRuleObject(): void
    {
        // Arrange: CSRF (implicit) + a RuleInterface object for a missing field
        $session = Session::getInstance();
        $token   = $session->getToken();

        $alwaysPass = new class implements RuleInterface {
            public function passes(string $attribute, mixed $value): bool { return true; }
            public function message(): string { return 'fail'; }
        };

        $rules = [$token => ['csrf', $alwaysPass]];

        // Act + Assert: must fail on CSRF (implicit rule runs), not crash on the inline object
        try {
            Validator::validate([], $rules);
            $this->fail('Expected ValidationException for missing CSRF token');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($token, $e->errors());
        }
    }

    /**
     * validateSize() returns false when the parameter is missing or non-numeric.
     *
     * This defensive path prevents 'size' from silently accepting arbitrary values
     * when misconfigured (e.g. 'size:' with no value, or 'size:abc').
     */
    public function testSizeRuleWithInvalidParameter(): void
    {
        // 'size' with no parameter value after the colon → parameters[0] = ''
        try {
            Validator::validate(['v' => 'abc'], ['v' => 'size:']);
            $this->fail('Expected ValidationException for size with empty parameter');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('v', $e->errors());
        }
    }

    /**
     * validateSize() returns false for values that are not string, array, or numeric.
     *
     * Objects, resources, and booleans are not measurable by 'size' — the rule
     * fails rather than throwing, keeping validation composable.
     */
    public function testSizeRuleWithUnsupportedType(): void
    {
        // Arrange: an object is not a string, array, or numeric value
        try {
            Validator::validate(['v' => new \stdClass()], ['v' => 'size:1']);
            $this->fail('Expected ValidationException for object value with size rule');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('v', $e->errors());
        }
    }

    /**
     * compareDates() returns false when the threshold parameter is missing entirely.
     *
     * If someone writes 'before' with no date argument (e.g. a misconfigured rule),
     * the validator must fail gracefully rather than crashing.
     */
    public function testBeforeRuleWithNoThresholdParameter(): void
    {
        // Arrange: 'before' with no parameter → $parameters[0] is unset → null threshold
        try {
            Validator::validate(['date' => '2025-01-01'], ['date' => 'before']);
            $this->fail('Expected ValidationException for before with no threshold');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('date', $e->errors());
        }
    }

    /**
     * compareDates() returns false when strtotime cannot parse the value.
     *
     * An unparseable value must produce a validation error, not a PHP warning
     * or an incorrect comparison result.
     */
    public function testBeforeRuleWithUnparseableDateValue(): void
    {
        // Arrange: 'before:2025-01-01' but the value is not a valid date
        try {
            Validator::validate(['date' => 'not-a-date'], ['date' => 'before:2025-01-01']);
            $this->fail('Expected ValidationException for unparseable date value');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('date', $e->errors());
        }
    }

    /**
     * RuleInterface objects may be passed inline in the rules array (no registration needed).
     *
     * This avoids polluting the global rule registry for one-off validations,
     * such as in a specific controller action.
     */
    public function testInlineRuleInterfaceObject(): void
    {
        // Arrange: rule that rejects reserved usernames
        $reserved = new class implements RuleInterface {
            private array $reserved = ['admin', 'root', 'system'];
            public function passes(string $attribute, mixed $value): bool
            {
                return !in_array(strtolower((string) $value), $this->reserved, true);
            }
            public function message(): string
            {
                return 'The :attribute is a reserved name.';
            }
        };

        $rules = ['username' => ['required', 'string', $reserved]];

        // Assert: non-reserved name passes
        $this->assertSame(
            ['username' => 'johndoe'],
            Validator::validate(['username' => 'johndoe'], $rules)
        );

        // Assert: reserved name fails
        try {
            Validator::validate(['username' => 'admin'], $rules);
            $this->fail('Expected ValidationException for reserved username');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('username', $e->errors());
            // The message comes from the RuleInterface, not the default messages map
            $this->assertStringContainsString('reserved', $e->errors()['username'][0]);
        }
    }
}
