<?php

namespace Pramnos\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Pramnos\Support\Faker;
use Pramnos\Support\FakerBaseProvider;
use Pramnos\Support\FakerGrProvider;
use Pramnos\Support\FakerProvider;
use Pramnos\Support\FakerUniqueProxy;

/**
 * Unit tests for the mini-Faker system:
 *  - Faker (generator + dispatch)
 *  - FakerBaseProvider (text, names, internet, numbers, dates)
 *  - FakerGrProvider (Greek names, addresses, phone numbers, identifiers)
 *  - FakerUniqueProxy (unique-value tracking)
 *  - FakerProvider static helpers (randomElement, numerify, lexify, bothify)
 */
#[\PHPUnit\Framework\Attributes\CoversClass(Faker::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(FakerProvider::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(FakerBaseProvider::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(FakerGrProvider::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(FakerUniqueProxy::class)]
class FakerTest extends TestCase
{
    // =========================================================================
    // Faker — create() factory
    // =========================================================================

    /**
     * create() with no argument defaults to 'el_GR' and registers GrProvider,
     * making Greek names available immediately.
     */
    public function testCreateDefaultsToElGr(): void
    {
        // Act
        $faker          = Faker::create();
        $providerTypes  = array_map('get_class', $faker->getProviders());

        // Assert — GrProvider present; produces non-empty name
        $this->assertContains(FakerGrProvider::class, $providerTypes);
        $this->assertNotEmpty($faker->name());
    }

    /**
     * create('en_US') registers only BaseProvider — no GrProvider.
     */
    public function testCreateEnUsDoesNotRegisterGrProvider(): void
    {
        // Act
        $faker         = Faker::create('en_US');
        $providerTypes = array_map('get_class', $faker->getProviders());

        // Assert
        $this->assertNotContains(FakerGrProvider::class, $providerTypes);
        $this->assertContains(FakerBaseProvider::class, $providerTypes);
    }

    /**
     * addProvider() adds a custom provider that is searched before previously
     * registered ones — the last-added provider wins for overlapping methods.
     */
    public function testAddProviderPrependsToSearchOrder(): void
    {
        // Arrange — custom provider that overrides name()
        $faker    = Faker::create('en_US');
        $custom   = new class($faker) extends FakerProvider {
            public function name(): string { return 'Custom Name'; }
        };

        // Act
        $faker->addProvider($custom);

        // Assert — custom provider wins
        $this->assertSame('Custom Name', $faker->name());
    }

    /**
     * getProviders() returns the registered providers in priority order.
     * For el_GR only GrProvider is registered (it extends BaseProvider).
     */
    public function testGetProvidersReturnsRegisteredProviders(): void
    {
        // Arrange
        $faker = Faker::create('el_GR');

        // Act
        $providers = $faker->getProviders();

        // Assert — exactly one provider (GrProvider, which extends BaseProvider)
        $this->assertCount(1, $providers);
        $this->assertInstanceOf(FakerGrProvider::class, $providers[0]);
    }

    /**
     * __call() throws BadMethodCallException when no provider implements the
     * requested method. This gives a clear error instead of a silent null.
     */
    public function testCallThrowsOnUnknownMethod(): void
    {
        // Arrange
        $faker = Faker::create('en_US');

        // Act + Assert
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessageMatches('/nonExistentMethod/');
        $faker->nonExistentMethod();
    }

    /**
     * __get() dispatches to the equivalent no-arg method call, allowing
     * property-style access: $faker->email instead of $faker->email().
     */
    public function testMagicGetDispatchesToMethod(): void
    {
        // Arrange
        $faker = Faker::create('en_US');

        // Act — property access
        $emailProp   = $faker->email;
        $emailMethod = $faker->email();

        // Assert — both are non-empty strings (values differ due to randomness,
        // but the return type and non-emptiness are stable)
        $this->assertIsString($emailProp);
        $this->assertNotEmpty($emailProp);
        $this->assertIsString($emailMethod);
    }

    // =========================================================================
    // FakerUniqueProxy
    // =========================================================================

    /**
     * unique() returns a FakerUniqueProxy instance.
     */
    public function testUniqueReturnsProxy(): void
    {
        $faker = Faker::create('en_US');
        $this->assertInstanceOf(FakerUniqueProxy::class, $faker->unique());
    }

    /**
     * Successive calls to unique()->safeEmail() return distinct values.
     * We generate 5 emails and assert all are different — with a 3-domain pool
     * and random usernames the collision probability is negligible.
     */
    public function testUniqueProxyReturnsDifferentValues(): void
    {
        // Arrange
        $faker  = Faker::create('en_US');
        $emails = [];

        // Act — collect 5 unique emails
        for ($i = 0; $i < 5; $i++) {
            $emails[] = $faker->unique()->safeEmail();
        }

        // Assert — all values are distinct
        $this->assertCount(5, array_unique($emails));
    }

    /**
     * unique() tracks values per-method independently: the seen registry for
     * 'safeEmail' does not affect the registry for 'uuid'.
     */
    public function testUniqueProxyTracksPerMethod(): void
    {
        // Arrange
        $faker = Faker::create('en_US');
        $faker->unique()->safeEmail(); // seeds the safeEmail registry

        // Act — uuid registry is separate, should not be affected
        $uuid = $faker->unique()->uuid();

        // Assert — uuid was returned and the seen array has two separate keys
        $this->assertIsString($uuid);
        $seen = $faker->unique()->getSeen();
        $this->assertArrayHasKey('safeEmail', $seen);
        $this->assertArrayHasKey('uuid', $seen);
    }

    /**
     * unique(true) resets all tracked values, allowing the same values to be
     * returned again in subsequent calls.
     */
    public function testUniqueWithResetClearsAllSeen(): void
    {
        // Arrange — call unique once to populate the registry
        $faker = Faker::create('en_US');
        $faker->unique()->safeEmail();

        // Assert — registry is non-empty before reset
        $this->assertNotEmpty($faker->unique()->getSeen());

        // Act — reset
        $faker->unique(true);

        // Assert — registry is empty after reset
        $this->assertEmpty($faker->unique()->getSeen());
    }

    /**
     * FakerUniqueProxy::reset(method) clears only the named method's registry,
     * leaving other methods' tracking intact.
     */
    public function testUniqueProxyResetSingleMethod(): void
    {
        // Arrange
        $faker = Faker::create('en_US');
        $faker->unique()->safeEmail();
        $faker->unique()->uuid();

        // Act — reset only safeEmail
        $faker->unique()->reset('safeEmail');

        // Assert — safeEmail cleared; uuid intact
        $seen = $faker->unique()->getSeen();
        $this->assertArrayNotHasKey('safeEmail', $seen);
        $this->assertArrayHasKey('uuid', $seen);
    }

    // =========================================================================
    // FakerBaseProvider — text
    // =========================================================================

    /**
     * word() returns a non-empty string from the lorem pool.
     */
    public function testWordReturnsNonEmptyString(): void
    {
        $faker = Faker::create('en_US');
        $word  = $faker->word();
        $this->assertIsString($word);
        $this->assertNotEmpty($word);
    }

    /**
     * words(n) returns exactly n strings.
     */
    public function testWordsReturnsRequestedCount(): void
    {
        $faker = Faker::create('en_US');
        $words = $faker->words(5);
        $this->assertCount(5, $words);
        $this->assertContainsOnly('string', $words);
    }

    /**
     * sentence() ends with a period and starts with an uppercase letter.
     */
    public function testSentenceEndsWithPeriodAndStartsUppercase(): void
    {
        $faker    = Faker::create('en_US');
        $sentence = $faker->sentence();
        $this->assertStringEndsWith('.', $sentence);
        $this->assertSame(strtoupper($sentence[0]), $sentence[0]);
    }

    /**
     * sentences(n) returns exactly n strings, each ending with a period.
     */
    public function testSentencesReturnsRequestedCount(): void
    {
        $faker     = Faker::create('en_US');
        $sentences = $faker->sentences(3);
        $this->assertCount(3, $sentences);
        foreach ($sentences as $s) {
            $this->assertStringEndsWith('.', $s);
        }
    }

    /**
     * paragraph() returns a non-empty string (multiple sentences joined).
     */
    public function testParagraphReturnsNonEmptyString(): void
    {
        $faker = Faker::create('en_US');
        $this->assertNotEmpty($faker->paragraph());
    }

    /**
     * text($maxChars) returns a string no longer than $maxChars.
     */
    public function testTextRespectsMaxLength(): void
    {
        $faker = Faker::create('en_US');
        $text  = $faker->text(100);
        $this->assertLessThanOrEqual(100, strlen($text));
    }

    // =========================================================================
    // FakerBaseProvider — names
    // =========================================================================

    /**
     * firstName('male') and firstName('female') return non-empty strings.
     * With el_GR locale these will be Greek names.
     */
    public function testFirstNameReturnsByGender(): void
    {
        $faker = Faker::create('el_GR');
        $this->assertNotEmpty($faker->firstName('male'));
        $this->assertNotEmpty($faker->firstName('female'));
    }

    /**
     * firstName() with no gender picks randomly — it should not throw.
     */
    public function testFirstNameWithNoGenderDoesNotThrow(): void
    {
        $faker = Faker::create('el_GR');
        $this->assertNotEmpty($faker->firstName());
    }

    /**
     * lastName() returns a non-empty string.
     */
    public function testLastNameReturnsNonEmptyString(): void
    {
        $faker = Faker::create('el_GR');
        $this->assertNotEmpty($faker->lastName());
    }

    /**
     * name() returns "first last" — two words separated by a space.
     */
    public function testNameReturnsTwoWordString(): void
    {
        $faker = Faker::create('el_GR');
        $name  = $faker->name();
        $parts = explode(' ', $name);
        $this->assertGreaterThanOrEqual(2, count($parts));
    }

    // =========================================================================
    // FakerBaseProvider — internet
    // =========================================================================

    /**
     * safeEmail() always uses one of the safe example.* domains.
     */
    public function testSafeEmailUsesSafeDomain(): void
    {
        $faker = Faker::create('en_US');
        for ($i = 0; $i < 10; $i++) {
            $email  = $faker->safeEmail();
            $domain = substr($email, strpos($email, '@') + 1);
            $this->assertMatchesRegularExpression('/^example\.(com|net|org)$/', $domain);
        }
    }

    /**
     * email() contains '@' and a valid-looking domain.
     */
    public function testEmailContainsAtSign(): void
    {
        $faker = Faker::create('en_US');
        $this->assertStringContainsString('@', $faker->email());
    }

    /**
     * userName() returns a non-empty string with no spaces (safe for URLs).
     */
    public function testUserNameHasNoSpaces(): void
    {
        $faker = Faker::create('en_US');
        $this->assertStringNotContainsString(' ', $faker->userName());
    }

    /**
     * url() starts with 'https://'.
     */
    public function testUrlStartsWithHttps(): void
    {
        $faker = Faker::create('en_US');
        $this->assertStringStartsWith('https://', $faker->url());
    }

    /**
     * ipv4() matches the dotted-quad format (4 groups of 1–3 digits).
     */
    public function testIpv4MatchesFormat(): void
    {
        $faker = Faker::create('en_US');
        $this->assertMatchesRegularExpression('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $faker->ipv4());
    }

    /**
     * slug() returns words joined by hyphens, no spaces.
     */
    public function testSlugContainsHyphensNoSpaces(): void
    {
        $faker = Faker::create('en_US');
        $slug  = $faker->slug(3);
        $this->assertStringContainsString('-', $slug);
        $this->assertStringNotContainsString(' ', $slug);
    }

    /**
     * password() length is within the requested bounds.
     */
    public function testPasswordLengthIsWithinBounds(): void
    {
        $faker    = Faker::create('en_US');
        $password = $faker->password(10, 15);
        $this->assertGreaterThanOrEqual(10, strlen($password));
        $this->assertLessThanOrEqual(15, strlen($password));
    }

    // =========================================================================
    // FakerBaseProvider — numbers
    // =========================================================================

    /**
     * numberBetween(min, max) always returns a value in [min, max].
     */
    public function testNumberBetweenIsWithinRange(): void
    {
        $faker = Faker::create('en_US');
        for ($i = 0; $i < 20; $i++) {
            $n = $faker->numberBetween(10, 20);
            $this->assertGreaterThanOrEqual(10, $n);
            $this->assertLessThanOrEqual(20, $n);
        }
    }

    /**
     * randomFloat(2, 0, 1) returns a float in [0, 1] with at most 2 decimal places.
     */
    public function testRandomFloatIsWithinRange(): void
    {
        $faker = Faker::create('en_US');
        $f     = $faker->randomFloat(2, 0, 1);
        $this->assertGreaterThanOrEqual(0.0, $f);
        $this->assertLessThanOrEqual(1.0, $f);
    }

    /**
     * randomNumber(n, strict=true) returns a number with exactly n digits.
     */
    public function testRandomNumberStrictHasExactDigits(): void
    {
        $faker = Faker::create('en_US');
        $n     = $faker->randomNumber(4, true);
        $this->assertSame(4, strlen((string) $n));
    }

    /**
     * randomDigit() returns an integer in [0, 9].
     */
    public function testRandomDigitIsInRange(): void
    {
        $faker = Faker::create('en_US');
        $d     = $faker->randomDigit();
        $this->assertGreaterThanOrEqual(0, $d);
        $this->assertLessThanOrEqual(9, $d);
    }

    /**
     * randomDigitNotZero() returns an integer in [1, 9].
     */
    public function testRandomDigitNotZeroIsInRange(): void
    {
        $faker = Faker::create('en_US');
        for ($i = 0; $i < 10; $i++) {
            $d = $faker->randomDigitNotZero();
            $this->assertGreaterThanOrEqual(1, $d);
            $this->assertLessThanOrEqual(9, $d);
        }
    }

    /**
     * randomLetter() returns a single lowercase ASCII letter.
     */
    public function testRandomLetterIsLowercaseLetter(): void
    {
        $faker = Faker::create('en_US');
        $this->assertMatchesRegularExpression('/^[a-z]$/', $faker->randomLetter());
    }

    /**
     * randomElement(array) picks one of the provided values.
     */
    public function testRandomElementPicksFromArray(): void
    {
        $faker    = Faker::create('en_US');
        $elements = ['a', 'b', 'c'];
        for ($i = 0; $i < 10; $i++) {
            $this->assertContains($faker->randomElement($elements), $elements);
        }
    }

    /**
     * randomElement() throws InvalidArgumentException for an empty array.
     */
    public function testRandomElementThrowsForEmptyArray(): void
    {
        $faker = Faker::create('en_US');
        $this->expectException(\InvalidArgumentException::class);
        $faker->randomElement([]);
    }

    /**
     * boolean(100) always returns true; boolean(0) always returns false.
     */
    public function testBooleanRespectsChance(): void
    {
        $faker = Faker::create('en_US');
        $this->assertTrue($faker->boolean(100));
        $this->assertFalse($faker->boolean(0));
    }

    // =========================================================================
    // FakerBaseProvider — UUID
    // =========================================================================

    /**
     * uuid() returns a string matching the UUID v4 format:
     * xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
     */
    public function testUuidMatchesFormat(): void
    {
        $faker = Faker::create('en_US');
        $uuid  = $faker->uuid();
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid
        );
    }

    // =========================================================================
    // FakerBaseProvider — dates
    // =========================================================================

    /**
     * dateTime() returns a \DateTime instance.
     */
    public function testDateTimeReturnsDateTimeInstance(): void
    {
        $faker = Faker::create('en_US');
        $this->assertInstanceOf(\DateTime::class, $faker->dateTime());
    }

    /**
     * dateTimeBetween() returns a date within the requested range.
     */
    public function testDateTimeBetweenIsWithinRange(): void
    {
        $faker = Faker::create('en_US');
        $start = new \DateTime('2020-01-01');
        $end   = new \DateTime('2020-12-31');
        $dt    = $faker->dateTimeBetween($start, $end);
        $this->assertGreaterThanOrEqual($start->getTimestamp(), $dt->getTimestamp());
        $this->assertLessThanOrEqual($end->getTimestamp(), $dt->getTimestamp());
    }

    /**
     * date() returns a string matching the given format.
     */
    public function testDateMatchesRequestedFormat(): void
    {
        $faker = Faker::create('en_US');
        $date  = $faker->date('Y-m-d');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $date);
    }

    /**
     * time() returns HH:MM:SS format.
     */
    public function testTimeMatchesFormat(): void
    {
        $faker = Faker::create('en_US');
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}$/', $faker->time());
    }

    /**
     * year() returns an integer within the default [1970, 2025] range.
     */
    public function testYearIsWithinRange(): void
    {
        $faker = Faker::create('en_US');
        $y     = $faker->year();
        $this->assertGreaterThanOrEqual(1970, $y);
        $this->assertLessThanOrEqual(2025, $y);
    }

    /**
     * unixTime() returns an integer timestamp (non-negative).
     */
    public function testUnixTimeReturnsNonNegativeInt(): void
    {
        $faker = Faker::create('en_US');
        $this->assertGreaterThanOrEqual(0, $faker->unixTime());
    }

    // =========================================================================
    // FakerProvider — static helpers
    // =========================================================================

    /**
     * numerify() replaces every '#' with a digit.
     */
    public function testNumerifyReplacesHashWithDigit(): void
    {
        $faker  = Faker::create('en_US');
        $result = $faker->numerify('ORD-####');
        $this->assertMatchesRegularExpression('/^ORD-\d{4}$/', $result);
    }

    /**
     * lexify() replaces every '?' with a lowercase letter.
     */
    public function testLexifyReplacesQuestionMarkWithLetter(): void
    {
        $faker  = Faker::create('en_US');
        $result = $faker->lexify('KEY-????');
        $this->assertMatchesRegularExpression('/^KEY-[a-z]{4}$/', $result);
    }

    /**
     * bothify() applies both numerify and lexify.
     */
    public function testBothifyAppliesBothReplacements(): void
    {
        $faker  = Faker::create('en_US');
        $result = $faker->bothify('??-##');
        $this->assertMatchesRegularExpression('/^[a-z]{2}-\d{2}$/', $result);
    }

    /**
     * BaseProvider::name() is called when no locale provider overrides it.
     * With en_US locale the base implementation is exercised directly.
     */
    public function testBaseProviderNameReturnsFullName(): void
    {
        $faker = Faker::create('en_US');
        $name  = $faker->name();
        $this->assertStringContainsString(' ', $name);
        $this->assertNotEmpty($name);
    }

    /**
     * randomNumber(n, strict=false) returns a number with at most n digits
     * (but may have fewer — not a strict exact-length requirement).
     */
    public function testRandomNumberNonStrictReturnsAtMostNDigits(): void
    {
        $faker = Faker::create('en_US');
        $n     = $faker->randomNumber(4, false);
        $this->assertLessThanOrEqual(9999, $n);
        $this->assertGreaterThanOrEqual(0, $n);
    }

    /**
     * dateTimeBetween() accepts string date arguments (not just \DateTimeInterface).
     * The result must be within the specified range.
     */
    public function testDateTimeBetweenAcceptsStringArguments(): void
    {
        $faker  = Faker::create('en_US');
        $result = $faker->dateTimeBetween('2020-01-01', '2020-12-31');
        $this->assertGreaterThanOrEqual(
            (new \DateTime('2020-01-01'))->getTimestamp(),
            $result->getTimestamp()
        );
        $this->assertLessThanOrEqual(
            (new \DateTime('2020-12-31'))->getTimestamp(),
            $result->getTimestamp()
        );
    }

    // =========================================================================
    // FakerGrProvider — names
    // =========================================================================

    /**
     * With el_GR locale, firstName() returns a Greek-script name (contains at
     * least one character outside basic ASCII, since all Greek names do).
     */
    public function testElGrFirstNameIsGreek(): void
    {
        $faker = Faker::create('el_GR');
        // Greek characters are outside ASCII range
        $name  = $faker->firstName();
        $this->assertMatchesRegularExpression('/[^\x00-\x7F]/', $name,
            'Expected Greek (non-ASCII) characters in firstName for el_GR locale');
    }

    /**
     * el_GR lastName() returns a Greek-script surname.
     */
    public function testElGrLastNameIsGreek(): void
    {
        $faker = Faker::create('el_GR');
        $this->assertMatchesRegularExpression('/[^\x00-\x7F]/', $faker->lastName());
    }

    // =========================================================================
    // FakerGrProvider — address
    // =========================================================================

    /**
     * city() returns a non-empty string (Greek city name).
     */
    public function testCityReturnsNonEmptyString(): void
    {
        $faker = Faker::create('el_GR');
        $this->assertNotEmpty($faker->city());
    }

    /**
     * streetName() starts with a recognised Greek street prefix (Οδός, Λεωφόρος, Πλατεία).
     */
    public function testStreetNameStartsWithKnownPrefix(): void
    {
        $faker   = Faker::create('el_GR');
        $street  = $faker->streetName();
        $prefixes = ['Οδός', 'Λεωφόρος', 'Πλατεία'];
        $hasPrefix = array_filter($prefixes, fn(string $p) => str_starts_with($street, $p));
        $this->assertNotEmpty($hasPrefix, "streetName '{$street}' has no known Greek prefix");
    }

    /**
     * streetAddress() includes a street number (ends with a digit).
     */
    public function testStreetAddressEndsWithNumber(): void
    {
        $faker = Faker::create('el_GR');
        $this->assertMatchesRegularExpression('/\d+$/', $faker->streetAddress());
    }

    /**
     * postcode() is a 5-digit string starting with a non-zero digit.
     */
    public function testPostcodeIsValid(): void
    {
        $faker = Faker::create('el_GR');
        for ($i = 0; $i < 10; $i++) {
            $pc = $faker->postcode();
            $this->assertMatchesRegularExpression('/^[1-8]\d{4}$/', $pc,
                "postcode '{$pc}' does not match Greek format");
        }
    }

    /**
     * address() contains at least one comma (city/postcode separator).
     */
    public function testAddressContainsSeparator(): void
    {
        $faker = Faker::create('el_GR');
        $this->assertStringContainsString(',', $faker->address());
    }

    /**
     * region() returns one of the 13 Greek administrative regions.
     */
    public function testRegionIsKnownGreekRegion(): void
    {
        $faker   = Faker::create('el_GR');
        $regions = [
            'Αττική', 'Κεντρική Μακεδονία', 'Θεσσαλία', 'Δυτική Ελλάδα',
            'Πελοπόννησος', 'Κρήτη', 'Ανατολική Μακεδονία και Θράκη', 'Ήπειρος',
            'Στερεά Ελλάδα', 'Δυτική Μακεδονία', 'Βόρειο Αιγαίο', 'Νότιο Αιγαίο',
            'Ιόνια Νησιά',
        ];
        $this->assertContains($faker->region(), $regions);
    }

    // =========================================================================
    // FakerGrProvider — phone numbers
    // =========================================================================

    /**
     * phoneNumber() is 10 digits (Greek landline format).
     */
    public function testPhoneNumberIs10Digits(): void
    {
        $faker = Faker::create('el_GR');
        $phone = $faker->phoneNumber();
        $this->assertMatchesRegularExpression('/^\d{10}$/', $phone);
    }

    /**
     * mobileNumber() starts with '69' followed by 8 more digits.
     */
    public function testMobileNumberStartsWith69(): void
    {
        $faker  = Faker::create('el_GR');
        $mobile = $faker->mobileNumber();
        $this->assertMatchesRegularExpression('/^69\d{8}$/', $mobile);
    }

    // =========================================================================
    // FakerGrProvider — identifiers
    // =========================================================================

    /**
     * vatNumber() returns a 9-digit ΑΦΜ string.
     */
    public function testVatNumberIs9Digits(): void
    {
        $faker = Faker::create('el_GR');
        $this->assertMatchesRegularExpression('/^\d{9}$/', $faker->vatNumber());
    }

    /**
     * amka() returns an 11-character ΑΜΚΑ string (DDMMYYXXXXX format).
     * First 6 characters represent a valid date (DD 01–28, MM 01–12).
     */
    public function testAmkaIs11CharsWithDatePrefix(): void
    {
        $faker = Faker::create('el_GR');
        $amka  = $faker->amka();
        $this->assertSame(11, strlen($amka));
        $this->assertMatchesRegularExpression('/^\d{11}$/', $amka);
        // First 2 = day (01–28), next 2 = month (01–12)
        $this->assertGreaterThanOrEqual(1,  (int) substr($amka, 0, 2));
        $this->assertLessThanOrEqual(28,    (int) substr($amka, 0, 2));
        $this->assertGreaterThanOrEqual(1,  (int) substr($amka, 2, 2));
        $this->assertLessThanOrEqual(12,    (int) substr($amka, 2, 2));
    }

    // =========================================================================
    // Integration — email uses locale-specific first/last name
    // =========================================================================

    /**
     * When el_GR locale is active, email() and safeEmail() route through
     * userName() → firstName()/lastName() which are served by GrProvider.
     * The username portion should NOT be empty (transliteration of Greek
     * characters via strtolower still produces a non-empty string).
     */
    public function testEmailWithElGrLocaleIsNonEmpty(): void
    {
        $faker = Faker::create('el_GR');
        $email = $faker->safeEmail();
        $this->assertNotEmpty($email);
        $this->assertStringContainsString('@', $email);
    }
}
