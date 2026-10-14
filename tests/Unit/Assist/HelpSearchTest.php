<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Assist;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Assist\Help;
use Pramnos\Assist\HelpBook;
use Pramnos\Assist\HelpReader;

/**
 * Which page a question finds, on pages shaped like a real guide: a short page that lists the
 * question, among long ones that use the question's common words over and over.
 *
 * Found on an application's guide: "How do I add a goal?" — listed word for word on the Goals
 * page — ranked five long pages above it, because "how" and "add" counted as much as "goal",
 * and "goal" never met "goals".
 */
#[CoversClass(Help::class)]
#[CoversClass(HelpBook::class)]
class HelpSearchTest extends TestCase
{
    private string $base = '';

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/pf-helpsearch-' . bin2hex(random_bytes(4));
        mkdir($this->base, 0777, true);
        $noise = str_repeat("How do I add what I need? How to add the thing, and how the page works. ", 12);
        $pages = [
            'goals'     => "---\ntitle: Goals and achievements\nquestions:\n  - How do I?\n  - How do I add a goal?\n  - What counts as an achievement?\n---\nSet a target and track it.",
            // (Goals lists a question of nothing but common words first: it matches nothing.)
        // Each also lists "how do I add" questions of its own, which count three times.
            'sites'     => "---\nquestions:\n  - How do I add a site?\n  - How do I add pages?\n---\n# Sites\n\n" . $noise . 'A site has pages.',
            'account'   => "---\nquestions:\n  - How do I add a user?\n---\n# Account\n\n" . $noise . 'Your account holds your goals for the year.',
            'editorial' => "---\nquestions:\n  - How do I add a draft?\n  - How do I add sources?\n---\n# Editorial\n\n" . $noise,
            'ask-ai'    => "---\nquestions:\n  - How do I add a question?\n---\n# Ask AI\n\n" . $noise,
            'publishing' => "---\nquestions:\n  - How do I add a channel?\n---\n# Publishing\n\n" . $noise,
            'logariasmos' => "---\ntitle: Ο λογαριασμός σας\nquestions:\n  - Πώς αλλάζω τον κωδικό μου;\n---\nΟ κωδικός αλλάζει από τις ρυθμίσεις.",
            'odigos'    => "# Οδηγός\n\n" . str_repeat('Πώς και για τον την του λογαριασμού, για να δείτε τι γίνεται στο σύστημα. ', 15),
        ];
        foreach ($pages as $slug => $text) {
            file_put_contents($this->base . '/' . $slug . '.md', $text);
        }
        Help::useSiteDirectories([$this->base]);
    }

    protected function tearDown(): void
    {
        Help::useSiteDirectories(null);
        foreach (glob($this->base . '/*.md') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->base);
    }

    /** The slugs a question finds, best first. */
    private function find(string $question): array
    {
        return array_column(Help::site()->for(HelpReader::guest())->search($question, 5), 'slug');
    }

    /** The question a page lists, word for word, finds that page first. */
    public function testTheListedQuestionWins(): void
    {
        // Act
        $found = $this->find('How do I add a goal?');

        // Assert
        $this->assertSame('goals', $found[0]);
    }

    /**
     * A four-letter word meets the longer one it begins — `goal` and `goals` — and the page that
     * is about goals outranks the one that mentions them once.
     */
    public function testAShortWordMeetsItsLongerForm(): void
    {
        // Act
        $found = $this->find('goal');

        // Assert
        $this->assertSame('goals', $found[0]);
        $this->assertContains('account', $found, 'the passing mention still counts');
        // Three letters stay exact: `add` does not find a page about addresses.
        file_put_contents($this->base . '/addresses.md', "# Addresses\n\nWhere the post goes.");
        Help::forget();
        $this->assertNotContains('addresses', $this->find('add'));
        $this->assertSame([], $this->find('the how what'), 'nothing but common words asks nothing');
    }

    /** A Greek question, articles and all, finds its page over a long one full of them. */
    public function testAGreekQuestionWithArticlesFindsItsPage(): void
    {
        // Act
        $found = $this->find('Πώς αλλάζω τον κωδικό του λογαριασμού μου;');

        // Assert
        $this->assertSame('logariasmos', $found[0]);
        $this->assertSame(['αλλαζ', 'κωδικ', 'λογαρ'], Help::words('Πώς αλλάζω τον κωδικό του λογαριασμού μου;'));
    }

    /**
     * A question whose every word is in a listed one, though not the same question, still puts
     * the page above pages that only use its words.
     */
    public function testANearQuestionRanksHigh(): void
    {
        // Act
        $found = $this->find('add goal');

        // Assert
        $this->assertSame('goals', $found[0]);
    }
}
