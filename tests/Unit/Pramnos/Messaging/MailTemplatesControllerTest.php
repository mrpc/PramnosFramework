<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Messaging;

use PHPUnit\Framework\TestCase;
use Pramnos\Messaging\Controllers\MailTemplatesController;
use Pramnos\Messaging\MailTemplate;

/**
 * The screen the `messaging` feature never had.
 *
 * The table, the model and the `(category, language, type)` lookup shipped from the start;
 * nothing rendered them. So the wording of an application's own notifications was editable
 * only in a database client, which in practice meant it was not edited — a project wanting
 * to change a password-reset email changed the code that composes it and left the template
 * unused.
 */
class MailTemplatesControllerTest extends TestCase
{
    /**
     * The placeholders are read from the template, not from a list somebody maintains.
     *
     * A documented list goes stale the first time an application adds a placeholder; the
     * ones a template *has* are the ones in it.
     */
    public function testPlaceholdersComeFromTheTemplateItself(): void
    {
        // Arrange
        $template = new MailTemplate(new \Pramnos\Application\Controller());
        $template->defaulttext    = 'Hello {name}, your code is {code}. Bye {name}.';
        $template->defaultsubject = 'Reset for {name} at {site.name}';

        // Act
        $found = MailTemplatesController::placeholders($template);

        // Assert — each once, from both fields, dots allowed
        $this->assertSame(['name', 'code', 'site.name'], $found);
    }

    /**
     * A template with no placeholders reports none.
     *
     * It says the same thing to everybody, which is a legitimate template and must not
     * render as an empty list of something.
     */
    public function testATemplateWithoutPlaceholdersReportsNone(): void
    {
        // Arrange
        $template = new MailTemplate(new \Pramnos\Application\Controller());
        $template->defaulttext    = 'The service will be unavailable tonight.';
        $template->defaultsubject = 'Maintenance';

        // Act & Assert
        $this->assertSame([], MailTemplatesController::placeholders($template));
    }

    /**
     * Braces that are not placeholders are left alone.
     *
     * An email template contains CSS, and `{ color: red }` is not a variable. A greedy
     * pattern would list every declaration as a placeholder and the panel would be noise.
     */
    public function testCssBracesAreNotPlaceholders(): void
    {
        // Arrange
        $template = new MailTemplate(new \Pramnos\Application\Controller());
        $template->defaulttext = '<style>.b { color: red; }</style> Hello {name}';

        // Act
        $found = MailTemplatesController::placeholders($template);

        // Assert
        $this->assertSame(['name'], $found);
    }

    /**
     * The three channels the model numbers are the three the screen offers.
     *
     * A screen offering a channel the model does not know would write a type nothing reads;
     * one missing a channel makes it uneditable.
     */
    public function testTheChannelsMatchTheModel(): void
    {
        // Assert
        $this->assertSame(
            [MailTemplate::TYPE_EMAIL, MailTemplate::TYPE_SMS, MailTemplate::TYPE_PUSH],
            array_keys(MailTemplatesController::TYPES)
        );
    }
}
