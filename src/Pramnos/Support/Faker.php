<?php

namespace Pramnos\Support;

/**
 * Lightweight Faker generator — zero external dependencies.
 *
 * Providers are registered in order; the last registered provider has the
 * highest priority for any given method name. This lets locale providers
 * (e.g. GrProvider) override base methods cleanly.
 *
 * Static construction:
 *
 *   $faker = Faker::create();          // el_GR by default
 *   $faker = Faker::create('en_US');   // English only
 *   $faker = Faker::create('el_GR');   // Greek names, addresses, phone numbers
 *
 * Calling generator methods:
 *
 *   $faker->name()               // Νίκος Παπαδόπουλος (el_GR)
 *   $faker->email()              // nikos.papadopoulos@gmail.com
 *   $faker->city()               // Θεσσαλονίκη
 *   $faker->unique()->safeEmail()
 *
 * Property-style access is also supported (calls the method with no args):
 *
 *   $faker->name    // same as $faker->name()
 *   $faker->uuid    // same as $faker->uuid()
 *
 * @package    PramnosFramework
 * @subpackage Support
 *
 * @method string  word()
 * @method list<string> words(int $nb = 3)
 * @method string  sentence(int $nbWords = 6, bool $variable = true)
 * @method list<string> sentences(int $nb = 3)
 * @method string  paragraph(int $nbSentences = 3, bool $variable = true)
 * @method string  text(int $maxNbChars = 200)
 * @method string  firstName(?string $gender = null)
 * @method string  lastName()
 * @method string  name(?string $gender = null)
 * @method string  userName()
 * @method string  safeEmail()
 * @method string  email()
 * @method string  url()
 * @method string  slug(int $nbWords = 3)
 * @method string  ipv4()
 * @method string  password(int $minLength = 8, int $maxLength = 20)
 * @method int     numberBetween(int $min = 0, int $max = PHP_INT_MAX)
 * @method float   randomFloat(int $maxDecimals = 2, float $min = 0.0, float $max = 100.0)
 * @method int     randomNumber(int $nbDigits = 5, bool $strict = false)
 * @method int     randomDigit()
 * @method int     randomDigitNotZero()
 * @method string  randomLetter()
 * @method mixed   randomElement(array $elements)
 * @method bool    boolean(int $chanceOfGettingTrue = 50)
 * @method string  uuid()
 * @method int     unixTime(string|\DateTimeInterface $max = 'now')
 * @method \DateTime dateTime(string|\DateTimeInterface $max = 'now')
 * @method \DateTime dateTimeBetween(string|\DateTimeInterface $start = '-30 years', string|\DateTimeInterface $end = 'now')
 * @method string  date(string $format = 'Y-m-d', string|\DateTimeInterface $max = 'now')
 * @method string  time(string $format = 'H:i:s')
 * @method int     year(int $min = 1970, int $max = 2025)
 * @method string  numerify(string $string = '###')
 * @method string  lexify(string $string = '????')
 * @method string  bothify(string $string = '## ??')
 * @method string  city()
 * @method string  streetName()
 * @method string  streetAddress()
 * @method string  postcode()
 * @method string  address()
 * @method string  region()
 * @method string  phoneNumber()
 * @method string  mobileNumber()
 * @method string  vatNumber()
 * @method string  amka()
 */
class Faker
{
    /**
     * Registered providers — stored in reverse registration order so that the
     * last-added provider is the first to be searched for any method name.
     *
     * @var list<FakerProvider>
     */
    private array $providers = [];

    private FakerUniqueProxy $uniqueProxy;

    public function __construct()
    {
        $this->uniqueProxy = new FakerUniqueProxy($this);
    }

    /**
     * Create a configured Faker generator for the given locale.
     *
     * Supported locales:
     *   'el_GR' (default) — Greek names, addresses, phone numbers, ΑΦΜ, ΑΜΚΑ
     *   'en_US'           — English names and generic internet/text helpers
     *
     * Both locales include all base methods (lorem, emails, numbers, dates).
     * Custom providers can be added at any time via addProvider().
     */
    public static function create(string $locale = 'el_GR'): static
    {
        $faker = new static();
        if (in_array($locale, ['el_GR', 'gr_GR'], true)) {
            // GrProvider extends BaseProvider — includes all generic methods
            $faker->addProvider(new FakerGrProvider($faker));
        } else {
            $faker->addProvider(new FakerBaseProvider($faker));
        }
        return $faker;
    }

    /**
     * Register an additional provider.
     * Later providers take priority: a method defined here shadows any earlier
     * provider that has the same method name.
     */
    public function addProvider(FakerProvider $provider): static
    {
        array_unshift($this->providers, $provider);
        return $this;
    }

    /** @return list<FakerProvider> */
    public function getProviders(): array
    {
        return $this->providers;
    }

    /**
     * Return the unique-value proxy.
     *
     * $faker->unique()->safeEmail()   — returns a different email on every call
     * $faker->unique(true)->uuid()    — resets all tracked values first
     */
    public function unique(bool $reset = false): FakerUniqueProxy
    {
        if ($reset) {
            $this->uniqueProxy->reset();
        }
        return $this->uniqueProxy;
    }

    /**
     * Dispatch a generator call to the first provider that implements the method.
     *
     * @throws \BadMethodCallException When no provider implements the method.
     */
    public function __call(string $name, array $args): mixed
    {
        foreach ($this->providers as $provider) {
            if (method_exists($provider, $name)) {
                return $provider->$name(...$args);
            }
        }
        throw new \BadMethodCallException("Call to undefined Faker method '{$name}'");
    }

    /**
     * Allow property-style access: $faker->email instead of $faker->email().
     * Calls the method with no arguments.
     */
    public function __get(string $name): mixed
    {
        return $this->$name();
    }
}
