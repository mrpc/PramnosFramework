<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Llm;

use Pramnos\Llm\AnswerTags;

/**
 * {@see AnswerTags} on its own: the built-in leaked-protocol shape, an application's own patterns
 * added beside it, a plain substring read case-insensitively, a `~`-prefixed one as a regular
 * expression, and ordinary prose in any language matching neither.
 */
class AnswerTagsTest extends LlmTestCase
{
    /**
     * The fullwidth-pipe tag several self-hosted models use for their own reserved tokens — the
     * real shape a provider's serving layer leaked into a production answer instead of routing it
     * through the structured tool-call field.
     */
    public function testLeakedFindsTheFullwidthPipeTag(): void
    {
        $leaked = 'answer text <｜｜DSML｜｜ calls>[{"name":"x","arguments":{}}]</｜｜DSML｜｜ calls>';

        $this->assertTrue(AnswerTags::leaked($leaked));
        $this->assertFalse(AnswerTags::leaked('Καλημέρα, δεν έχω το εργαλείο για αυτό.'), 'ordinary Greek prose, no leak');
        $this->assertFalse(AnswerTags::leaked('Plain English prose with no special tokens at all.'));
    }

    /** An application's own shape, beside the built-in one — not instead of it. */
    public function testLeakedAddsAnApplicationsOwnPattern(): void
    {
        $this->configure(['leaked_patterns' => ['~<\|tool_call\|>~']]);

        $this->assertTrue(AnswerTags::leaked('oops <|tool_call|>{"name":"x"}'), 'the application\'s own pattern');
        $this->assertTrue(AnswerTags::leaked('oops <｜｜X｜｜ calls>'), 'the built-in one still applies');
    }

    /** A plain substring is read case-insensitively; a `~`-prefixed one is a regular expression. */
    public function testMatchesSubstringsAndPatterns(): void
    {
        $this->assertTrue(AnswerTags::matches('Δεν έχω SQL για αυτό.', ['δεν έχω']), 'a plain substring, case-insensitive');
        $this->assertFalse(AnswerTags::matches('Δεν μπορώ να το κάνω αυτό.', ['μπορω']), 'no accent-folding — "μπορω" does not match "μπορώ"');
        $this->assertTrue(AnswerTags::matches('no tool for that', ['~\bno tool\b~i']));
        $this->assertFalse(AnswerTags::matches('Here is the answer.', ['δεν έχω', '~\bno tool\b~i']));
        $this->assertFalse(AnswerTags::matches('anything', ['']), 'an empty pattern matches nothing');
    }
}
