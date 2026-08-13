<?php

declare(strict_types=1);

namespace Pramnos\Auth;

/**
 * Thrown when an authorization check refuses.
 *
 * Before this existed the framework had **three** shapes for the same answer:
 * `Controller::auth()` returned `false`, `ApiCrudController::authorize()` returned `false`,
 * and the router threw a plain `\Exception` with code 403. Anything wanting to turn "not
 * allowed" into a response had to know which layer it came from.
 *
 * The code is **403** and it extends `\Exception`, so the router's existing
 * `catch (\Exception $e)` handlers — and anything checking `$e->getCode() === 403` — keep
 * working unchanged. What is new is that the failure now carries *what* was refused.
 *
 * ```php
 * try {
 *     Gate::authorize('update-post', $post);
 * } catch (AuthorizationException $e) {
 *     $e->getAbility();   // 'update-post'
 *     $e->getMessage();   // 'This action is unauthorized.' or the reason the rule gave
 * }
 * ```
 *
 * @see Gate::authorize()
 */
class AuthorizationException extends \Exception
{
    /**
     * HTTP status this failure means.
     *
     * @var int
     */
    public const STATUS = 403;

    /**
     * The ability that was refused.
     *
     * @var string
     */
    private string $ability;

    /**
     * @param string          $ability  The ability that was refused
     * @param string|null     $message  Why, if a rule said; the generic message otherwise
     * @param \Throwable|null $previous Underlying failure, if any
     */
    public function __construct(
        string $ability = '',
        ?string $message = null,
        ?\Throwable $previous = null
    ) {
        $this->ability = $ability;

        parent::__construct(
            $message ?? 'This action is unauthorized.',
            self::STATUS,
            $previous
        );
    }

    /**
     * The ability that was refused.
     *
     * Useful in a handler that wants to log or branch on *what* was denied rather than
     * only that something was.
     *
     * @return string The ability name, or an empty string if the thrower did not name one
     */
    public function getAbility(): string
    {
        return $this->ability;
    }
}
