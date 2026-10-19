<?php

declare(strict_types=1);

namespace Pramnos\Email;

/**
 * The application's own values for a message template, beside the framework's
 * ({@see TemplateValues}): what its users have that the framework's do not — credits, a
 * subscription's end, a photo.
 *
 * ```php
 * // app/app.php
 * 'email' => ['template_values' => App\Mail\Values::class],
 * ```
 *
 * The values go into the body as given — HTML stays HTML, so a `{photo}` can be an `<img>` — and a
 * value that is the user's own text must be escaped here.
 */
interface TemplateValueProvider
{
    /**
     * The values for this recipient, by placeholder name without the braces.
     *
     * @param object|null $user The recipient's account, where there is one
     * @return array<string, scalar>
     */
    public function values(?object $user): array;
}
