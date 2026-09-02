<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Init;

/**
 * The password `init` generates for a new installation's first account.
 *
 * Seven statements, never executed — for a value somebody signs in with. Three properties matter,
 * and each one is a decision rather than an accident:
 *
 * - **It is drawn with `random_int()`**, which is the CSPRNG. `rand()` here would make the first
 *   administrator's password predictable from the time the project was scaffolded.
 * - **The alphabet excludes look-alikes** — no `i`, `l`, `o`, `I`, `L`, `O`, `0` or `1`. This is a
 *   password read off a terminal and typed by hand, so `l` against `1` is a support conversation
 *   rather than a typo.
 * - **Sixteen characters by default**, from a 62-character alphabet.
 *
 * A generator cannot be tested for randomness, so what is asserted is the shape: the length, the
 * alphabet, the absence of the excluded characters, and that two calls differ. The last is the
 * cheapest possible check against the worst possible bug — a constant.
 */
#[CoversClass(Init::class)]
class GeneratedPasswordTest extends TestCase
{
    private function generate(int $length = 16): string
    {
        return (new \ReflectionMethod(Init::class, 'generateRandomPassword'))
            ->invoke(new Init(), $length);
    }

    /** The default is sixteen characters. */
    public function testTheDefaultLengthIsSixteen(): void
    {
        // Act + Assert
        $this->assertSame(16, strlen($this->generate()));
    }

    /** A requested length is honoured exactly. */
    public function testARequestedLengthIsHonoured(): void
    {
        // Act + Assert
        $this->assertSame(8, strlen($this->generate(8)));
        $this->assertSame(64, strlen($this->generate(64)));
    }

    /**
     * Every character comes from the declared alphabet.
     *
     * Checked over a large sample rather than one password: a generator that occasionally emitted
     * something outside its alphabet — an off-by-one on `strlen($chars) - 1` reaching the
     * terminating position — would show up once in sixty-odd draws, which one password would miss.
     */
    public function testEveryCharacterComesFromTheDeclaredAlphabet(): void
    {
        // Arrange
        $allowed = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789!@#$%^&*';

        // Act — 200 characters' worth
        $sample = '';
        for ($i = 0; $i < 20; $i++) {
            $sample .= $this->generate(10);
        }

        // Assert
        $this->assertSame(
            '',
            preg_replace('/[' . preg_quote($allowed, '/') . ']/', '', $sample),
            'a character outside the alphabet was generated'
        );
    }

    /**
     * The look-alikes are absent, which is the point of the alphabet.
     *
     * `i`/`l`/`1`, `o`/`O`/`0`, `I`/`L` — this password is read off a terminal and typed into a
     * form by a person. Every one of those pairs is a failed sign-in that looks like a wrong
     * password rather than a misread character.
     */
    public function testTheLookAlikeCharactersAreNeverGenerated(): void
    {
        // Act — a large sample, because absence needs one
        $sample = '';
        for ($i = 0; $i < 50; $i++) {
            $sample .= $this->generate(16);
        }

        // Assert
        foreach (['i', 'l', 'o', 'I', 'L', 'O', '0', '1'] as $lookAlike) {
            $this->assertStringNotContainsString(
                $lookAlike,
                $sample,
                'the alphabet emitted "' . $lookAlike . '", which is misread when typed by hand'
            );
        }
    }

    /**
     * Two passwords differ.
     *
     * The cheapest check against the worst bug. A generator returning a constant would satisfy
     * every other assertion here, and every installation scaffolded with it would share one
     * administrator password.
     */
    public function testTwoPasswordsDiffer(): void
    {
        // Act
        $first  = $this->generate();
        $second = $this->generate();

        // Assert
        $this->assertNotSame($first, $second);
    }

    /**
     * A sample of many is not a handful of repeats.
     *
     * Stronger than the test above and still not a randomness test: with 62 characters and length
     * 16 there is no plausible mechanism by which twenty draws collide, so any repeat means the
     * source is not what it claims.
     */
    public function testASampleOfManyHasNoRepeats(): void
    {
        // Act
        $passwords = [];
        for ($i = 0; $i < 20; $i++) {
            $passwords[] = $this->generate();
        }

        // Assert
        $this->assertCount(20, array_unique($passwords), 'the generator repeated itself');
    }
}
