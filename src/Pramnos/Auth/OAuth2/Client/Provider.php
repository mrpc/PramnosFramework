<?php

declare(strict_types=1);

namespace Pramnos\Auth\OAuth2\Client;

/**
 * One third-party provider this application signs in to, as configuration.
 *
 * OAuth2 is a specification that every large provider implements slightly differently, and
 * the differences are almost all *data* rather than behaviour: a different endpoint, a
 * different name for the same parameter, credentials in the body instead of in a header.
 * So they live here as options rather than as subclasses — a provider that deviates in one
 * named way needs a value, not a class.
 *
 * ```php
 * $google = new Provider(
 *     name: 'google',
 *     authorizeUrl: 'https://accounts.google.com/o/oauth2/v2/auth',
 *     tokenUrl: 'https://oauth2.googleapis.com/token',
 *     clientId: getenv('GOOGLE_CLIENT_ID'),
 *     clientSecret: getenv('GOOGLE_CLIENT_SECRET'),
 *     redirectUri: sURL . 'connect/google/callback',
 *     scopes: ['openid', 'email', 'https://www.googleapis.com/auth/youtube.readonly'],
 *     authorizeParams: ['access_type' => 'offline', 'prompt' => 'consent'],
 * );
 * ```
 *
 * **What is deliberately not here.** Some providers have a second leg that is not a
 * parameter — Instagram exchanges the short-lived token the code flow returns for a
 * long-lived one, at a different endpoint with a different grant. That is a step, not a
 * setting, and inventing an abstraction for it against providers this framework cannot
 * test would be guessing at the shape. An application performs that exchange itself and
 * stores the result through {@see ConnectionStore::save()}; the guide says so.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license     MIT
 */
final class Provider
{
    /**
     * Credentials in an `Authorization: Basic` header — RFC 6749 §2.3.1's preferred form,
     * and what most providers document.
     */
    public const AUTH_BASIC = 'basic';

    /**
     * Credentials as form fields in the request body. The specification permits it, and
     * several providers accept **only** this — a `Basic` header gets `invalid_client`,
     * which reads as a wrong secret rather than as the wrong place to put a correct one.
     */
    public const AUTH_BODY = 'body';

    /**
     * @param string               $name            Stored on every connection; the key an application looks one up by
     * @param string               $authorizeUrl    Where the browser is sent
     * @param string               $tokenUrl        Where a code is exchanged, and where a refresh goes unless $refreshUrl says otherwise
     * @param string               $clientId        This application's id with the provider
     * @param string               $clientSecret    Its secret. Never logged and never stored by this subsystem
     * @param string               $redirectUri     Must match what is registered with the provider **exactly**, character for character
     * @param list<string>         $scopes          Asked for; the provider decides what it grants, and the grant is what gets stored
     * @param array<string,string> $authorizeParams Extra query parameters on the authorize URL — `access_type`, `prompt`, `duration`
     * @param array<string,string> $tokenParams     Extra form fields on the token and refresh requests
     * @param string               $authMethod      self::AUTH_BASIC or self::AUTH_BODY
     * @param string               $clientIdParam   What the provider calls `client_id`. TikTok calls it `client_key`
     * @param string               $clientSecretParam What it calls `client_secret`
     * @param string               $scopeSeparator  Space per the specification; a handful of providers use a comma
     * @param string               $refreshUrl      A separate refresh endpoint, when the provider has one
     * @param string               $refreshMethod   `POST` per the specification. Instagram refreshes with a `GET`
     * @param string               $refreshGrantType `refresh_token` per the specification. Instagram calls it `ig_refresh_token`
     * @param bool                 $usePkce         Send a PKCE challenge. Required by some providers, harmless everywhere else
     */
    public function __construct(
        public readonly string $name,
        public readonly string $authorizeUrl,
        public readonly string $tokenUrl,
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly string $redirectUri,
        public readonly array  $scopes = [],
        public readonly array  $authorizeParams = [],
        public readonly array  $tokenParams = [],
        public readonly string $authMethod = self::AUTH_BASIC,
        public readonly string $clientIdParam = 'client_id',
        public readonly string $clientSecretParam = 'client_secret',
        public readonly string $scopeSeparator = ' ',
        public readonly string $refreshUrl = '',
        public readonly string $refreshMethod = 'POST',
        public readonly string $refreshGrantType = 'refresh_token',
        public readonly bool   $usePkce = false,
    ) {
    }

    /** Where a refresh goes: the dedicated endpoint when there is one, the token endpoint otherwise. */
    public function refreshEndpoint(): string
    {
        return $this->refreshUrl !== '' ? $this->refreshUrl : $this->tokenUrl;
    }

    /** The scopes as the provider wants them on the wire. */
    public function scopeString(): string
    {
        return implode($this->scopeSeparator, $this->scopes);
    }
}
