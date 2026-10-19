<?php

declare(strict_types=1);

namespace Pramnos\Auth;

use Pramnos\Application\Settings;

/**
 * Whose server this is: the name, logo, colour and footer the auth screens show.
 *
 * ```php
 * Brand::current();   // ['name' => …, 'logo' => …, 'primary_color' => …, 'primary_color_set' => …, 'footer' => …]
 * ```
 *
 * From the `auth_brand_name` (else `sitename`), `auth_brand_logo`, `auth_brand_primary_color` and
 * `auth_brand_footer` settings. The `auth_brand` partial reads it when a controller has not handed
 * a view its `brand`, so every screen somebody reaches from a link — the second factor, the consent
 * screen, a device code — says whose it is before it asks for anything.
 *
 * `primary_color` always holds a colour, for code that prints one; `primary_color_set` says
 * whether an administrator chose it. The views apply the colour only then, so a theme with its own
 * primary — a daisyUI theme, a dark one with a different primary — keeps it.
 */
final class Brand
{
    /**
     * The brand, as the settings say.
     *
     * @return array{name: string, logo: string, primary_color: string, primary_color_set: bool, footer: string}
     */
    public static function current(): array
    {
        $setting = static fn (string $key): string => (string) (Settings::getSetting($key) ?? '');
        $name    = $setting('auth_brand_name');
        if ($name === '') {
            $name = $setting('sitename');
        }
        $color = $setting('auth_brand_primary_color');

        return [
            'name'          => $name !== '' ? $name : 'Sign in',
            'logo'          => $setting('auth_brand_logo'),
            'primary_color' => $color !== '' ? $color : '#2563eb',
            'primary_color_set' => $color !== '',
            'footer'        => $setting('auth_brand_footer'),
        ];
    }
}
