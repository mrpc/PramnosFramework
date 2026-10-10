<?php

namespace Pramnos\Auth;

/**
 * The webhook event types an endpoint may subscribe to: the framework's, and an application's.
 *
 * The framework's eight describe accounts and tokens. An application's events are its own —
 * a radio directory's *a station went on the air*, a shop's *an order shipped* — and the
 * delivery machinery (signing, retries, `auth:webhook-deliver`, the private-network refusal)
 * does not care what an event means. So the set is open, in the shape
 * {@see \Pramnos\Messaging\SystemMailTemplates::register()} already has:
 *
 * ```php
 * WebhookEvents::register([
 *     'station.live' => ['title' => 'A station went on the air', 'payload' => ['station_id', 'slug']],
 * ]);
 * ```
 *
 * Call it from a service provider's `boot()` or the application's `Application.php`, so every
 * request and every worker sees the same list.
 *
 * **The framework's own win a collision.** Their payloads are written by the framework, and a
 * registration redescribing `token_revoked` would advertise fields that are not sent.
 *
 * **A type nobody registered is still refused.** That is what the database's CHECK constraint
 * was for — a typo becoming an endpoint that never fires — and it now lives in
 * {@see WebhookService::saveEndpoint()}, where the list can include the application's.
 */
final class WebhookEvents
{
    /**
     * Types an application registered. {@see register()}
     *
     * @var array<string, array{title: string, payload: list<string>}>
     */
    private static array $registered = [];

    /**
     * Declare event types this application sends.
     *
     * A name is at most 50 characters — the column's width — and made of letters, digits,
     * `.`, `_` and `-`. A dotted namespace (`station.live`) keeps an application's names apart
     * from the framework's underscored ones.
     *
     * @param array<string, array{title?: string, payload?: list<string>}> $entries
     * @return void
     * @throws \InvalidArgumentException When a name would not fit the column or is not a plain identifier.
     */
    public static function register(array $entries): void
    {
        foreach ($entries as $type => $entry) {
            $type = (string) $type;
            if (!preg_match('/^[A-Za-z0-9._-]{1,50}$/', $type)) {
                throw new \InvalidArgumentException(
                    "Webhook event type '{$type}' must be 1-50 letters, digits, '.', '_' or '-'."
                );
            }
            self::$registered[$type] = [
                'title'   => (string) ($entry['title'] ?? $type),
                'payload' => array_values(array_map('strval', (array) ($entry['payload'] ?? []))),
            ];
        }
    }

    /**
     * Forget every registration.
     *
     * Registrations are process-wide, and a test run is one process.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$registered = [];
    }

    /**
     * Every type an endpoint may subscribe to, with its description.
     *
     * The framework's are merged **last**, so they cannot be redefined.
     *
     * @return array<string, array{title: string, payload: list<string>}>
     */
    public static function all(): array
    {
        return array_merge(self::$registered, self::builtIn());
    }

    /**
     * The names alone, built-in first — what a subscription screen offers and a validator checks.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_values(array_unique(array_merge(
            array_keys(self::builtIn()),
            array_keys(self::$registered)
        )));
    }

    /**
     * Whether an endpoint may subscribe to this type.
     *
     * @param string $type
     * @return bool
     */
    public static function isKnown(string $type): bool
    {
        return isset(self::builtIn()[$type]) || isset(self::$registered[$type]);
    }

    /**
     * The types the framework itself sends. {@see WebhookService::EVENT_TYPES} is the same list.
     *
     * @return array<string, array{title: string, payload: list<string>}>
     */
    public static function builtIn(): array
    {
        return [
            'user_deauthorized'    => ['title' => 'A user removed this application', 'payload' => []],
            'token_revoked'        => ['title' => 'A token was revoked', 'payload' => []],
            'gdpr_request'         => ['title' => 'A user asked for their data or its erasure', 'payload' => []],
            'user_profile_changed' => ['title' => 'A user changed their profile', 'payload' => []],
            'device_authorized'    => ['title' => 'A user approved a device sign-in', 'payload' => ['user_code', 'client_id', 'scope']],
            'device_deauthorized'  => ['title' => 'A device was signed out', 'payload' => []],
            'account_deleted'      => ['title' => 'An account was deleted', 'payload' => []],
            'scope_changed'        => ['title' => 'The scopes granted to this application changed', 'payload' => []],
            'permissions_changed'  => ['title' => 'What a user or a role may do changed', 'payload' => ['subject_type', 'subject_id']],
        ];
    }
}
