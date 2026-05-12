<?php

namespace Pramnos\Support;

/**
 * Generic Faker provider — text, names, internet, numbers, and dates.
 *
 * Protected static helpers (prefixed with `_`) are used internally by this
 * class and by subclasses (e.g. GrProvider). The public instance methods
 * are the ones exposed via the generator dispatch in Faker::__call().
 *
 * Methods that need to compose other generator methods call $this->generator
 * so that subclass overrides cascade correctly. For example:
 *   email() → $this->generator->userName() → $this->generator->firstName()
 * If GrProvider overrides firstName(), it automatically affects emails.
 *
 * @package    PramnosFramework
 * @subpackage Support
 */
class FakerBaseProvider extends FakerProvider
{
    // =========================================================================
    // Data pools
    // =========================================================================

    /** @var list<string> */
    protected static array $loremWords = [
        'lorem', 'ipsum', 'dolor', 'sit', 'amet', 'consectetur', 'adipiscing',
        'elit', 'sed', 'eiusmod', 'tempor', 'incididunt', 'labore', 'dolore',
        'magna', 'aliqua', 'enim', 'veniam', 'quis', 'exercitation', 'ullamco',
        'laboris', 'aliquip', 'commodo', 'consequat', 'aute', 'irure',
        'reprehenderit', 'voluptate', 'cillum', 'fugiat', 'nulla', 'pariatur',
        'excepteur', 'occaecat', 'cupidatat', 'proident', 'culpa', 'officia',
        'deserunt', 'mollit', 'laborum', 'perspiciatis', 'unde', 'omnis',
        'natus', 'voluptatem', 'accusantium', 'laudantium', 'totam', 'aperiam',
        'inventore', 'veritatis', 'beatae', 'dicta', 'aspernatur', 'porro',
        'quisquam', 'eveniet', 'rerum', 'temporibus', 'deleniti', 'expedita',
        'recusandae', 'praesentium', 'blanditiis', 'dignissimos', 'velit',
        'quasi', 'provident', 'architecto',
    ];

    /** @var list<string> */
    protected static array $maleFirstNames = [
        'James', 'John', 'Robert', 'Michael', 'William', 'David', 'Richard',
        'Joseph', 'Thomas', 'Charles', 'Christopher', 'Daniel', 'Matthew',
        'Anthony', 'Donald', 'Mark', 'Paul', 'Steven', 'Andrew', 'Kenneth',
        'George', 'Joshua', 'Kevin', 'Brian', 'Edward', 'Ronald', 'Timothy',
        'Jason', 'Jeffrey', 'Ryan', 'Jacob', 'Eric', 'Stephen', 'Jonathan',
    ];

    /** @var list<string> */
    protected static array $femaleFirstNames = [
        'Mary', 'Patricia', 'Jennifer', 'Linda', 'Barbara', 'Elizabeth',
        'Susan', 'Jessica', 'Sarah', 'Karen', 'Nancy', 'Lisa', 'Betty',
        'Margaret', 'Sandra', 'Ashley', 'Dorothy', 'Kimberly', 'Emily',
        'Donna', 'Michelle', 'Carol', 'Amanda', 'Melissa', 'Deborah',
        'Stephanie', 'Rebecca', 'Sharon', 'Laura', 'Cynthia', 'Amy',
    ];

    /** @var list<string> */
    protected static array $lastNames = [
        'Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller',
        'Davis', 'Rodriguez', 'Martinez', 'Hernandez', 'Lopez', 'Gonzalez',
        'Wilson', 'Anderson', 'Thomas', 'Taylor', 'Moore', 'Jackson', 'Martin',
        'Lee', 'Perez', 'Thompson', 'White', 'Harris', 'Sanchez', 'Clark',
        'Ramirez', 'Lewis', 'Robinson', 'Walker', 'Young', 'Allen', 'King',
    ];

    /** @var list<string> */
    protected static array $safeEmailDomains = ['example.com', 'example.net', 'example.org'];

    /** @var list<string> */
    protected static array $freeEmailDomains = [
        'gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'protonmail.com',
    ];

    /** @var list<string> */
    protected static array $tlds = ['com', 'net', 'org', 'io', 'co', 'dev'];

    // =========================================================================
    // Protected static helpers (internal use — subclasses may call these)
    // =========================================================================

    /**
     * Pick a random element from an array.
     * @param array<mixed> $elements
     * @throws \InvalidArgumentException When the array is empty.
     */
    protected static function _randomElement(array $elements): mixed
    {
        if (empty($elements)) {
            throw new \InvalidArgumentException('Cannot pick from an empty array');
        }
        return $elements[array_rand($elements)];
    }

    /** Random integer in [$min, $max]. */
    protected static function _numberBetween(int $min = 0, int $max = PHP_INT_MAX): int
    {
        return mt_rand($min, $max);
    }

    /** Random float rounded to $maxDecimals decimal places. */
    protected static function _randomFloat(int $maxDecimals = 2, float $min = 0.0, float $max = 100.0): float
    {
        $scale = 10 ** $maxDecimals;
        return mt_rand((int) ($min * $scale), (int) ($max * $scale)) / $scale;
    }

    /** Random lowercase letter a–z. */
    protected static function _randomLetter(): string
    {
        return chr(mt_rand(97, 122));
    }

    /** Random digit 0–9. */
    protected static function _randomDigit(): int
    {
        return mt_rand(0, 9);
    }

    /** Replace every '#' in $string with a random digit. */
    protected static function _numerify(string $string): string
    {
        return (string) preg_replace_callback('/#/', static fn() => (string) mt_rand(0, 9), $string);
    }

    /** Replace every '?' in $string with a random lowercase letter. */
    protected static function _lexify(string $string): string
    {
        return (string) preg_replace_callback('/\?/', static fn() => chr(mt_rand(97, 122)), $string);
    }

    // =========================================================================
    // Text
    // =========================================================================

    public function word(): string
    {
        return static::_randomElement(static::$loremWords);
    }

    /** @return list<string> */
    public function words(int $nb = 3): array
    {
        $result = [];
        for ($i = 0; $i < $nb; $i++) {
            $result[] = $this->word();
        }
        return $result;
    }

    public function sentence(int $nbWords = 6, bool $variable = true): string
    {
        if ($variable) {
            $nbWords = max(3, $nbWords + mt_rand(-2, 2));
        }
        $words    = $this->words($nbWords);
        $words[0] = ucfirst($words[0]);
        return implode(' ', $words) . '.';
    }

    /** @return list<string> */
    public function sentences(int $nb = 3): array
    {
        $result = [];
        for ($i = 0; $i < $nb; $i++) {
            $result[] = $this->sentence();
        }
        return $result;
    }

    public function paragraph(int $nbSentences = 3, bool $variable = true): string
    {
        if ($variable) {
            $nbSentences = max(2, $nbSentences + mt_rand(-1, 1));
        }
        return implode(' ', $this->sentences($nbSentences));
    }

    public function text(int $maxNbChars = 200): string
    {
        $text = '';
        while (mb_strlen($text) < $maxNbChars) {
            $text .= ' ' . $this->sentence();
        }
        return rtrim(mb_substr($text, 1, $maxNbChars), ' .');
    }

    // =========================================================================
    // Names
    // =========================================================================

    public function firstName(?string $gender = null): string
    {
        $gender = $gender ?? (mt_rand(0, 1) ? 'male' : 'female');
        return $gender === 'male'
            ? static::_randomElement(static::$maleFirstNames)
            : static::_randomElement(static::$femaleFirstNames);
    }

    public function lastName(): string
    {
        return static::_randomElement(static::$lastNames);
    }

    public function name(?string $gender = null): string
    {
        return $this->generator->firstName($gender) . ' ' . $this->generator->lastName();
    }

    // =========================================================================
    // Internet
    // =========================================================================

    public function userName(): string
    {
        $fn       = strtolower($this->generator->firstName());
        $ln       = strtolower($this->generator->lastName());
        $formats  = [
            fn() => $fn . '.' . $ln,
            fn() => $fn . static::_numberBetween(1, 999),
            fn() => substr($fn, 0, 1) . $ln,
            fn() => $fn . '_' . $ln,
        ];
        return static::_randomElement($formats)();
    }

    public function safeEmail(): string
    {
        return $this->generator->userName() . '@' . static::_randomElement(static::$safeEmailDomains);
    }

    public function email(): string
    {
        return $this->generator->userName() . '@' . static::_randomElement(static::$freeEmailDomains);
    }

    public function url(): string
    {
        $tld  = static::_randomElement(static::$tlds);
        $name = strtolower(static::_randomElement(static::$lastNames));
        return 'https://www.' . $name . '.' . $tld;
    }

    public function slug(int $nbWords = 3): string
    {
        return implode('-', $this->words($nbWords));
    }

    public function ipv4(): string
    {
        return implode('.', [
            mt_rand(1, 254), mt_rand(0, 255), mt_rand(0, 255), mt_rand(1, 254),
        ]);
    }

    public function password(int $minLength = 8, int $maxLength = 20): string
    {
        $length  = static::_numberBetween($minLength, $maxLength);
        $charset = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        $chars   = str_split($charset);
        $result  = '';
        for ($i = 0; $i < $length; $i++) {
            $result .= static::_randomElement($chars);
        }
        return $result;
    }

    // =========================================================================
    // Numbers
    // =========================================================================

    public function numberBetween(int $min = 0, int $max = PHP_INT_MAX): int
    {
        return static::_numberBetween($min, $max);
    }

    public function randomFloat(int $maxDecimals = 2, float $min = 0.0, float $max = 100.0): float
    {
        return static::_randomFloat($maxDecimals, $min, $max);
    }

    public function randomNumber(int $nbDigits = 5, bool $strict = false): int
    {
        if ($strict) {
            return static::_numberBetween((int) (10 ** ($nbDigits - 1)), (int) (10 ** $nbDigits) - 1);
        }
        return static::_numberBetween(0, (int) (10 ** $nbDigits) - 1);
    }

    public function randomDigit(): int
    {
        return static::_randomDigit();
    }

    public function randomDigitNotZero(): int
    {
        return mt_rand(1, 9);
    }

    public function randomLetter(): string
    {
        return static::_randomLetter();
    }

    /** @param array<mixed> $elements */
    public function randomElement(array $elements): mixed
    {
        return static::_randomElement($elements);
    }

    public function boolean(int $chanceOfGettingTrue = 50): bool
    {
        return mt_rand(1, 100) <= $chanceOfGettingTrue;
    }

    // =========================================================================
    // UUID
    // =========================================================================

    public function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    // =========================================================================
    // Dates
    // =========================================================================

    public function unixTime(string|\DateTimeInterface $max = 'now'): int
    {
        $maxTs = $max instanceof \DateTimeInterface ? $max->getTimestamp() : (int) strtotime((string) $max);
        return mt_rand(0, max(0, $maxTs));
    }

    public function dateTime(string|\DateTimeInterface $max = 'now'): \DateTime
    {
        return (new \DateTime())->setTimestamp($this->unixTime($max));
    }

    public function dateTimeBetween(
        string|\DateTimeInterface $startDate = '-30 years',
        string|\DateTimeInterface $endDate   = 'now'
    ): \DateTime {
        $start = $startDate instanceof \DateTimeInterface
            ? $startDate->getTimestamp()
            : (int) strtotime((string) $startDate);
        $end = $endDate instanceof \DateTimeInterface
            ? $endDate->getTimestamp()
            : (int) strtotime((string) $endDate);
        return (new \DateTime())->setTimestamp(mt_rand(min($start, $end), max($start, $end)));
    }

    public function date(string $format = 'Y-m-d', string|\DateTimeInterface $max = 'now'): string
    {
        return $this->dateTime($max)->format($format);
    }

    public function time(string $format = 'H:i:s'): string
    {
        return sprintf('%02d:%02d:%02d', mt_rand(0, 23), mt_rand(0, 59), mt_rand(0, 59));
    }

    public function year(int $min = 1970, int $max = 2025): int
    {
        return static::_numberBetween($min, $max);
    }

    // =========================================================================
    // Formatting helpers
    // =========================================================================

    public function numerify(string $string = '###'): string
    {
        return static::_numerify($string);
    }

    public function lexify(string $string = '????'): string
    {
        return static::_lexify($string);
    }

    public function bothify(string $string = '## ??'): string
    {
        return static::_lexify(static::_numerify($string));
    }
}
