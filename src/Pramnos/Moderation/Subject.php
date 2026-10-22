<?php

declare(strict_types=1);

namespace Pramnos\Moderation;

/**
 * Whom moderation is about: an account, a visitor with no account (by the key their session holds),
 * an address, or a nickname. A nickname is kept as it compares — trimmed and lower-case — so `Maria`
 * banned is `maria ` banned.
 */
final class Subject
{
    /** The kinds there are. */
    public const KINDS = ['account', 'visitor', 'ip', 'nickname'];

    /**
     * @param string $kind  One of {@see KINDS}
     * @param string $value What identifies them within the kind
     */
    private function __construct(public readonly string $kind, public readonly string $value)
    {
    }

    /** An account, by its id. */
    public static function account(int $id): self
    {
        return self::of('account', (string) $id);
    }

    /** A visitor, by the key their session holds. */
    public static function visitor(string $key): self
    {
        return self::of('visitor', $key);
    }

    /** An address. */
    public static function ip(string $address): self
    {
        return self::of('ip', $address);
    }

    /** A nickname, as it compares. */
    public static function nickname(string $name): self
    {
        return self::of('nickname', $name);
    }

    /**
     * A subject from its kind and value — a row's two columns.
     *
     * @throws \InvalidArgumentException For a kind there is not, or nothing to name them by
     */
    public static function of(string $kind, string $value): self
    {
        $value = $kind === 'nickname' ? mb_strtolower(trim($value)) : trim($value);
        if (!in_array($kind, self::KINDS, true) || $value === '' || mb_strlen($value) > 191) {
            throw new \InvalidArgumentException(t('That is nobody moderation knows how to name.'));
        }

        return new self($kind, $value);
    }

    /** Whether two subjects are the same one. */
    public function is(self $other): bool
    {
        return $this->kind === $other->kind && $this->value === $other->value;
    }

    /** `account:12`, `ip:203.0.113.4` — for keys and the log. */
    public function key(): string
    {
        return $this->kind . ':' . $this->value;
    }
}
