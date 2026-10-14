<?php

declare(strict_types=1);

namespace Pramnos\Assist;

/**
 * Who is reading the site's help: what a {@see HelpGate} decides by.
 *
 * From the request — the signed-in user, or the API token's (an API request seals its
 * identity), and the organisation `OrganizationScope` resolves — or from an assistant's scope.
 */
final class HelpReader
{
    /** @var object|false|null The user, once loaded; false for none */
    private object|false|null $loaded;

    public function __construct(
        public readonly ?int $userId = null,
        public readonly ?int $organizationId = null,
        public readonly ?\Pramnos\Llm\Chat\Scope $scope = null,
        ?object $user = null
    ) {
        $this->loaded = $user ?? ($userId === null ? false : null);
    }

    /** Nobody: no user, no organisation. */
    public static function guest(): self
    {
        return new self();
    }

    /**
     * The reader of this request: its user, if any, and the organisation it is about. An
     * organisation the resolver cannot name — or a resolver that fails — is none.
     */
    public static function fromRequest(): self
    {
        $user = \Pramnos\User\User::current();
        try {
            $organization = \Pramnos\Auth\OrganizationScope::current();
        } catch (\Throwable) {
            $organization = null;
        }
        $userId = is_object($user) && (int) ($user->userid ?? 0) > 1 ? (int) $user->userid : null;

        return new self($userId, $organization, null, $userId === null ? null : $user);
    }

    /** The reader an assistant answers: its scope's person and organisation. */
    public static function fromScope(\Pramnos\Llm\Chat\Scope $scope): self
    {
        return new self($scope->userId > 1 ? $scope->userId : null, $scope->organizationId, $scope);
    }

    /** The user, loaded on first use; null for a guest. */
    public function user(): ?object
    {
        if ($this->loaded === null) {
            $user         = new \Pramnos\User\User((int) $this->userId);
            $this->loaded = (int) ($user->userid ?? 0) > 1 ? $user : false;
        }

        return $this->loaded === false ? null : $this->loaded;
    }
}
