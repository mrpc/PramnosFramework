<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Conversations;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Conversations\Markdown;
use Pramnos\Conversations\Messages;

/**
 * A message's markdown-lite as HTML: each mark it knows, and nothing the writer wrote as HTML —
 * a message is read by the whole team, so a script in one must reach nobody.
 */
#[CoversClass(Markdown::class)]
#[CoversClass(Messages::class)]
final class MarkdownTest extends TestCase
{
    /** Names for `<@id>`: one known, others not. */
    private static function nameOf(int $id): ?string
    {
        return $id === 5 ? 'Μαρία Κ.' : null;
    }

    /**
     * Each mark, as written and as shown.
     *
     * @return array<string, array{string, string}>
     */
    public static function marks(): array
    {
        return [
            'bold, italic, struck'  => ['**α** *β* _γ_ ~~δ~~', '<p><strong>α</strong> <em>β</em> <em>γ</em> <del>δ</del></p>'],
            // Underlined in pairs, as ~~ strikes; a `++` against a letter is C++ and stays text.
            'underlined'            => ['++α++ και ++β γ++', '<p><u>α</u> και <u>β γ</u></p>'],
            'not underlined'        => ['C++ και i++ ή ++ α ++ και C++x++', '<p>C++ και i++ ή ++ α ++ και C++x++</p>'],
            'inline code'           => ['τρέξε `rm -rf <x>`', '<p>τρέξε <code>rm -rf &lt;x&gt;</code></p>'],
            'fenced code'           => ["πριν\n```php\n<b>x</b>\n```\nμετά", '<p>πριν</p><pre class="pf-conv-code"><code>&lt;b&gt;x&lt;/b&gt;</code></pre><p>μετά</p>'],
            // Ask's chart: drawn by pf-chat.js over its code; a block that is not one stays code.
            'a chart'               => ["```chart\n{\"type\":\"pie\",\"title\":\"Μερίδιο\",\"labels\":[\"Α\"],\"series\":[{\"data\":[1]}]}\n```",
                '<div class="pf-chart" data-pf-chart="{&quot;type&quot;:&quot;pie&quot;,&quot;title&quot;:&quot;Μερίδιο&quot;,&quot;labels&quot;:[&quot;Α&quot;],&quot;series&quot;:[{&quot;data&quot;:[1]}]}" role="img" aria-label="Μερίδιο">'
                . '<pre class="pf-conv-code"><code>{&quot;type&quot;:&quot;pie&quot;,&quot;title&quot;:&quot;Μερίδιο&quot;,&quot;labels&quot;:[&quot;Α&quot;],&quot;series&quot;:[{&quot;data&quot;:[1]}]}</code></pre></div>'],
            // A table: the header, the alignment of each column, a wrapper that scrolls when wide.
            'a table'               => ["Ανά πυλώνα:\n| Πυλώνας | Επαγγελματίες | % |\n|---|:---:|---:|\n| **Φύλαξη** | 1.204 | 61 |\n| Κατοικίδια | 88 |\nτέλος",
                '<p>Ανά πυλώνα:</p><div class="pf-conv-table"><table><thead><tr><th>Πυλώνας</th><th style="text-align:center">Επαγγελματίες</th><th style="text-align:right">%</th></tr></thead>'
                . '<tbody><tr><td><strong>Φύλαξη</strong></td><td style="text-align:center">1.204</td><td style="text-align:right">61</td></tr>'
                . '<tr><td>Κατοικίδια</td><td style="text-align:center">88</td></tr></tbody></table></div><p>τέλος</p>'],
            // Drawn with lines (FW-394): a terminal's or a model's box, a cell over two lines joined,
            // the text around it kept; ASCII's, ruled under the heading, a | in a cell kept in it.
            'a drawn table'         => [implode("\n", [
                'Το ιστορικό:',
                '┌────────────┬──────────────────────────┐',
                '│    Πότε    │         Τι έγινε         │',
                '├────────────┼──────────────────────────┤',
                '│ 5/11 15:26 │ Αγοράζει Premium Ετήσιο  │',
                '├────────────┼──────────────────────────┤',
                '│ 6/11 17:28 │ Η thvasileiou την        │',
                '│            │ ξαναβάζει εξακριβωμένη   │',
                '└────────────┴──────────────────────────┘',
                'τέλος',
            ]), '<p>Το ιστορικό:</p><div class="pf-conv-table"><table><thead><tr><th>Πότε</th><th>Τι έγινε</th></tr></thead>'
                . '<tbody><tr><td>5/11 15:26</td><td>Αγοράζει Premium Ετήσιο</td></tr><tr><td>6/11 17:28</td><td>Η thvasileiou την ξαναβάζει εξακριβωμένη</td></tr></tbody></table></div><p>τέλος</p>'],
            'an ascii table'        => ["+----+-------+\n| id | name  |\n+----+-------+\n| 1  | a|b   |\n| 2  | c     |\n+----+-------+",
                '<div class="pf-conv-table"><table><thead><tr><th>id</th><th>name</th></tr></thead><tbody><tr><td>1</td><td>a|b</td></tr><tr><td>2</td><td>c</td></tr></tbody></table></div>'],
            // A line shifted off the rule (a wide character) is split at its bars instead.
            'a shifted line'        => ["┌───┬───┐\n│ α │ β │\n├───┼───┤\n│ 👍👍 │ γ │\n└───┴───┘",
                '<div class="pf-conv-table"><table><thead><tr><th>α</th><th>β</th></tr></thead><tbody><tr><td>👍👍</td><td>γ</td></tr></tbody></table></div>'],
            'one drawn line'        => ['│ just one │', '<p>│ just one │</p>'],
            'drawn, but in code'    => ["```\n+---+\n| a |\n| b |\n+---+\n```", '<pre class="pf-conv-code"><code>+---+' . "\n" . '| a |' . "\n" . '| b |' . "\n" . '+---+</code></pre>'],
            'pipes but no table'    => ["a | b\nc | d", '<p>a | b<br>c | d</p>'],
            'a chart that is not'   => ["```chart\n{\"type\":\"radar\"}\n```", '<pre class="pf-conv-code"><code>{&quot;type&quot;:&quot;radar&quot;}</code></pre>'],
            'a link'                => ['[Jira](https://jira.example/NAN-1)', '<p><a href="https://jira.example/NAN-1" target="_blank" rel="noopener noreferrer nofollow">Jira</a></p>'],
            'a bare address'        => ['δες https://example.com/a?b=1.', '<p>δες <a href="https://example.com/a?b=1" target="_blank" rel="noopener noreferrer nofollow">https://example.com/a?b=1</a>.</p>'],
            'no javascript link'    => ['[x](javascript:alert(1))', '<p>[x](javascript:alert(1))</p>'],
            'a list, then another'  => ["- α\n- β\n1. γ", '<ul><li>α</li><li>β</li></ul><ol><li>γ</li></ol>'],
            'a quote'               => ["> ένα\n> δύο\nτέλος", '<blockquote>ένα<br>δύο</blockquote><p>τέλος</p>'],
            'lines and paragraphs'  => ["α\nβ\n\nγ", '<p>α<br>β</p><p>γ</p>'],
            'a person'              => ['<@5> και <@9>', '<p><span class="pf-conv-mention" data-user="5">@Μαρία Κ.</span> και <span class="pf-conv-mention" data-user="9">@#9</span></p>'],
            'everybody, online'     => ['@all @online me@all.gr', '<p><span class="pf-conv-mention pf-conv-mention-all">@all</span> <span class="pf-conv-mention pf-conv-mention-online">@online</span> me@all.gr</p>'],
            'a reference'           => ['#user:48211 και #γενικά', '<p><span class="pf-conv-ref" data-ref="user:48211">#user:48211</span> και #γενικά</p>'],
            'emoji'                 => [':thumbsup: :unknown:', '<p>👍 :unknown:</p>'],
            'html stays text'       => ['<script>alert(1)</script><img src=x onerror=alert(1)>', '<p>&lt;script&gt;alert(1)&lt;/script&gt;&lt;img src=x onerror=alert(1)&gt;</p>'],
            'quotes in an address'  => ['https://x.gr/"onmouseover="a', '<p><a href="https://x.gr/" target="_blank" rel="noopener noreferrer nofollow">https://x.gr/</a>&quot;onmouseover=&quot;a</p>'],
        ];
    }

    /** Written as each case, shown as its HTML. */
    #[DataProvider('marks')]
    public function testEachMark(string $written, string $shown): void
    {
        // Arrange
        $name = self::nameOf(...);

        // Act
        $html = Markdown::render($written, $name);

        // Assert
        $this->assertSame($shown, $html);
    }

    /**
     * A preview or a notification leaves the marks out: an underline's pair of `++` goes, as
     * `~~` does, while a `++` that is part of a word — C++ — stays.
     */
    public function testAPreviewLeavesTheUnderlineOut(): void
    {
        // Arrange
        $body = '++Σημαντικό++: το C++ και το ~~παλιό~~';

        // Act
        $text = Messages::plain($body, []);

        // Assert
        $this->assertSame('Σημαντικό: το C++ και το παλιό', $text);
    }
}
