<?php

namespace Pramnos\Tests\Unit\Validation;

use PHPUnit\Framework\TestCase;
use Pramnos\Validation\Validator;
use Pramnos\Validation\ValidationException;
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
}
