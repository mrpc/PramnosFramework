<?php

declare(strict_types=1);

namespace Pramnos\Security;

use Pramnos\Application\Settings;
use Pramnos\Cache\Cache;
use Pramnos\Framework\Factory;

/**
 * A proof-of-work human check for public, unauthenticated writes.
 *
 * ## What this does, and what it does not
 *
 * It **prices** automated submissions. It does not stop them. A client is handed
 * a challenge and must find a nonce whose hash meets a difficulty target; there
 * is no shortcut, so solving it is paying for it. A thousand signups then cost
 * real compute instead of nothing.
 *
 * That is the correct defence against volume spam and **no defence at all**
 * against a targeted one. An attacker with a botnet and free CPU still gets
 * through. Anything reading a passed check must not conclude more than "this
 * submission cost something" — in particular it must not conclude "a human did
 * this", because nothing here can establish that.
 *
 * ## Why not a CAPTCHA
 *
 * Distorted text is solved by commodity OCR and by any vision model at rates
 * that make it decorative, and an image grid needs a labelled dataset. Either
 * would be a control that appears to work and does not, paid for by real users
 * — disproportionately those using a screen reader. Proof-of-work is arithmetic
 * rather than perception: there is nothing to recognise, so there is nothing a
 * model can recognise better than the honest client can compute.
 *
 * It is also invisible (it runs in a Web Worker while the visitor types),
 * involves no third party, and sends nothing anywhere.
 *
 * ## The cost it imposes on visitors
 *
 * It burns battery. On a cheap phone an over-tuned difficulty is seconds of jank
 * on a form, so difficulty is expressed as *milliseconds of work on a mid-range
 * phone* rather than as a leading-zero count nobody can reason about. The
 * default is deliberately modest.
 *
 * ## Usage
 *
 *     $check     = new HumanCheck();
 *     $challenge = $check->challenge();          // hand this to the page
 *
 *     // …later, on submit:
 *     if (!$check->verify($submitted['challenge'], $submitted['solution'])) {
 *         // refuse
 *     }
 *
 * The server stores nothing to hand a challenge out: the challenge is
 * HMAC-signed and carries its own expiry. It stores one cache key per *solved*
 * challenge, to make it single-use.
 *
 * @package Pramnos\Security
 */
class HumanCheck
{
    /**
     * Hashes per second assumed for a mid-range phone in a Web Worker.
     *
     * Deliberately conservative: guessing high would make the advertised
     * "300ms" mean several seconds on the slowest devices, and those are the
     * devices least able to spare it. Fast hardware simply finishes sooner,
     * which costs nothing.
     */
    private const HASHES_PER_SECOND = 250000;

    /** Difficulty floor and ceiling in leading zero bits. */
    private const MIN_BITS = 4;
    private const MAX_BITS = 26;

    /** How long a challenge remains solvable. */
    private const DEFAULT_TTL = 600;

    /**
     * @param int         $difficultyMs Target work in milliseconds on a
     *                                  mid-range phone. A signup and a login do
     *                                  not deserve the same cost — pass a
     *                                  different value per call site.
     * @param int         $ttl          Seconds a challenge stays valid.
     * @param string|null $secret       HMAC key. Defaults to the application's
     *                                  security salt.
     * @param Cache|null  $cache        Used to enforce single use.
     */
    public function __construct(
        private int     $difficultyMs = 300,
        private int     $ttl          = self::DEFAULT_TTL,
        ?string         $secret       = null,
        private ?Cache  $cache        = null
    ) {
        $this->secret = $secret ?? $this->applicationSecret();
        $this->cache  = $cache ?? Factory::getCache('humancheck');
    }

    /** @var string The HMAC key. */
    private string $secret;

    /**
     * Mint a challenge.
     *
     * Nothing is stored: the returned token carries its own difficulty and
     * expiry, signed, so a forged or edited one fails verification. That also
     * means handing out challenges cannot be used to fill a cache.
     *
     * @return array{challenge: string, difficulty: int, expires: int, algorithm: string}
     *         `challenge` goes to the client verbatim and comes back unchanged.
     */
    public function challenge(): array
    {
        $expires    = time() + $this->ttl;
        $difficulty = $this->bits();
        $nonce      = bin2hex(random_bytes(16));

        $payload = $nonce . '.' . $difficulty . '.' . $expires;

        return [
            'challenge'  => $payload . '.' . $this->sign($payload),
            'difficulty' => $difficulty,
            'expires'    => $expires,
            // Named so the client cannot be silently pointed at a weaker hash
            // by a later change without the mismatch being visible.
            'algorithm'  => 'sha256-leading-zero-bits',
        ];
    }

    /**
     * Verify a solution.
     *
     * Every failure returns false rather than throwing: a caller deciding
     * whether to accept a form submission wants an answer, and distinguishing
     * "expired" from "wrong" for the client would tell an attacker which knob to
     * turn.
     *
     * @param string $challenge The token from {@see challenge()}, unmodified.
     * @param string $solution  The nonce the client found.
     */
    public function verify(string $challenge, string $solution): bool
    {
        $parts = explode('.', $challenge);
        if (count($parts) !== 4) {
            return false;
        }

        [$nonce, $difficulty, $expires, $signature] = $parts;
        $payload = $nonce . '.' . $difficulty . '.' . $expires;

        // The signature first: without it the difficulty is client-controlled
        // and an attacker simply asks for zero bits of work.
        if (!hash_equals($this->sign($payload), $signature)) {
            return false;
        }

        if (!ctype_digit($expires) || (int) $expires < time()) {
            return false;
        }

        if ($solution === '' || strlen($solution) > 64) {
            return false;
        }

        if (!$this->meetsDifficulty($payload, $solution, (int) $difficulty)) {
            return false;
        }

        // Single use, last — a challenge is only spent once it was going to be
        // accepted, so a client whose solution was wrong can try again with the
        // one it already has.
        return $this->claim($nonce, (int) $expires);
    }

    /**
     * Whether hash(payload:solution) has the required number of leading zero
     * bits.
     *
     * Bits rather than characters: a leading-zero *character* count moves in
     * steps of 16× and gives no way to ask for "a bit more than 300ms".
     */
    public function meetsDifficulty(string $payload, string $solution, int $bits): bool
    {
        if ($bits < 1) {
            return false;
        }

        $digest = hash('sha256', $payload . ':' . $solution, true);

        $whole = intdiv($bits, 8);
        for ($i = 0; $i < $whole; $i++) {
            if ($digest[$i] !== "\0") {
                return false;
            }
        }

        $remaining = $bits % 8;
        if ($remaining === 0) {
            return true;
        }

        return (ord($digest[$whole]) >> (8 - $remaining)) === 0;
    }

    /**
     * Spend a challenge, returning false if it was already spent.
     *
     * A solved challenge replayed a thousand times is the obvious bypass, and
     * without this the whole check is worth nothing — one solve would buy
     * unlimited submissions.
     *
     * This is why it is an atomic increment and not a read-then-write: two
     * replays arriving together would both read "unused" and both be admitted.
     * The counter's own first-caller-sees-1 is the guarantee.
     *
     * Where the cache cannot count atomically (Array, File) the check degrades
     * to load-and-save, which closes the replay window but not the race. That is
     * a real limitation of those adapters, and an installation using this to do
     * security work should be on Redis or Memcached.
     */
    private function claim(string $nonce, int $expires): bool
    {
        $key = 'solved:' . $nonce;
        // Live slightly past the challenge's own expiry, so a replay cannot
        // wait out the record and reuse a challenge that is still valid.
        $ttl = max(1, $expires - time()) + 60;

        if ($this->cache->supportsAtomicCounter()) {
            return $this->cache->increment($key, $ttl) === 1;
        }

        if ($this->cache->load($key, null, $ttl) !== false) {
            return false;
        }
        $this->cache->save('1', $key);

        return true;
    }

    /**
     * The difficulty in leading zero bits for the configured millisecond
     * target.
     *
     * Expected work for n bits is 2^n hashes, so the bits for a time budget are
     * log2(hashes affordable in that time).
     */
    public function bits(): int
    {
        $hashes = max(1.0, ($this->difficultyMs / 1000) * self::HASHES_PER_SECOND);
        $bits   = (int) round(log($hashes, 2));

        return max(self::MIN_BITS, min(self::MAX_BITS, $bits));
    }

    /**
     * Sign a payload.
     */
    private function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->secret);
    }

    /**
     * The application's HMAC key.
     *
     * Falls back to a per-process random value when the application has no
     * security salt. That makes challenges unverifiable across processes, which
     * fails closed — an unconfigured installation rejects submissions rather
     * than accepting forged ones.
     */
    private function applicationSecret(): string
    {
        $salt = Settings::getSetting('securitySalt');

        if (is_string($salt) && $salt !== '') {
            return $salt;
        }

        return bin2hex(random_bytes(32));
    }
}
