<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Html\MessageComposer;

/**
 * The message composer's server half: the placeholders it offers, how they are filled for a
 * recipient, and the toolbar it draws.
 *
 * What holds: an account's fields fill from the account, an address-only recipient's from the
 * address with the rest empty; unknown placeholders are left as written; values are escaped in
 * HTML; the toolbar draws each part unless switched off, with its words for the script; and the
 * framework's message forms carry the composer.
 */
#[CoversClass(MessageComposer::class)]
final class MessageComposerTest extends TestCase
{
    /**
     * An account fills every field; an address fills only {email} and empties the rest; others
     * stay as written; in HTML a value is escaped.
     */
    public function testPlaceholdersAreFilledForTheRecipient(): void
    {
        // Arrange
        $user = (object) ['firstname' => 'Anna', 'lastname' => 'K<b>', 'username' => 'anna', 'email' => 'a@x.test'];

        // Act
        $values = MessageComposer::valuesFor($user);
        $html   = MessageComposer::fill('<p>{full_name} — {unknown}</p>', $values, true);
        $plain  = MessageComposer::fill('Hi {firstname}', $values);
        $byMail = MessageComposer::fill('{firstname}<{email}>', MessageComposer::valuesForAddress('s@x.test'));

        // Assert
        $this->assertSame('<p>Anna K&lt;b&gt; — {unknown}</p>', $html);
        $this->assertSame('Hi Anna', $plain);
        $this->assertSame('<s@x.test>', $byMail);
        $this->assertSame('no braces', MessageComposer::fill('no braces', $values));
    }

    /** The fields as the composer reads them: token and label, safe in a single-quoted attribute. */
    public function testTheFieldsJsonIsSafeInAnAttribute(): void
    {
        // Act
        $json = MessageComposer::fieldsJson(['{x}' => "It's <b>"]);

        // Assert
        $this->assertStringNotContainsString("'", $json);
        $this->assertStringNotContainsString('<', $json);
        $this->assertSame([['token' => '{x}', 'label' => "It's <b>"]], json_decode($json, true));
        $this->assertCount(5, json_decode(MessageComposer::fieldsJson(), true));
    }

    /** Each part is drawn unless switched off; the words the script uses travel with it. */
    public function testTheToolbarDrawsItsParts(): void
    {
        // Act
        $all  = MessageComposer::toolbar(['ai' => true]);
        $bare = MessageComposer::toolbar(['saved' => false, 'ai' => false, 'signature' => false]);

        // Assert
        foreach (['data-pf-composer-saved', 'data-pf-composer-save', 'data-pf-composer-forget', 'data-pf-composer-fields', 'data-pf-composer-write', 'data-pf-composer-signature', 'data-pf-composer-words', 'Composer/signature'] as $part) {
            $this->assertStringContainsString($part, $all, $part . ' is not drawn');
        }
        foreach (['data-pf-composer-saved', 'data-pf-composer-write', 'data-pf-composer-signature'] as $part) {
            $this->assertStringNotContainsString($part, $bare, $part . ' drawn though switched off');
        }
        $this->assertStringContainsString('data-pf-composer-fields', $bare, 'the placeholders have no switch');
        $this->assertSame('', MessageComposer::signature(), 'nobody signed in has no signature');
    }

    /** The framework's own message forms carry the composer, in every theme. */
    public function testTheMessageFormsCarryTheComposer(): void
    {
        foreach (['tailwind', 'bootstrap', 'plain-css'] as $theme) {
            foreach (['users/notify' => 'user-message', 'massmessages/edit' => 'mass-message'] as $view => $scope) {
                // Arrange / Act
                $source = (string) file_get_contents(dirname(__DIR__, 3) . '/scaffolding/themes/' . $theme . '/views/' . $view . '.html.php');

                // Assert
                $this->assertStringContainsString('data-pf-composer-scope="' . $scope . '"', $source, $theme . ' ' . $view);
                $this->assertStringContainsString('MessageComposer::toolbar()', $source, $theme . ' ' . $view);
                $this->assertStringContainsString('data-pf-composer-subject', $source, $theme . ' ' . $view);
                $this->assertStringContainsString('data-pf-composer-body', $source, $theme . ' ' . $view);
            }
        }
    }
}
