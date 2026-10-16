<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Document\Document;
use Pramnos\Document\DocumentTypes\Html;

/**
 * Speculation rules: the next page fetched before its link is clicked.
 *
 * What holds: nothing without the config or a page's rule; from the config, a rule over the
 * included paths that leaves out a sign-out, an export, a nofollow link, a `.no-prefetch` one,
 * the exclusions given and the page cache's glob bypass paths — not its regular expressions,
 * which are not URL patterns; the eagerness asked for, `moderate` otherwise; JSON that cannot
 * close its script early; hashed in the policy, so no nonce and the page stays cacheable; and a
 * page's own rule added beside them, which carries the nonce instead.
 */
#[CoversClass(Document::class)]
#[CoversClass(Html::class)]
class SpeculationRulesTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $info = [];

    protected function setUp(): void
    {
        $this->info = Application::getInstance()->applicationInfo;
    }

    protected function tearDown(): void
    {
        Application::getInstance()->applicationInfo = $this->info;
    }

    /** A page drawn with the configuration given. */
    private function render(array $speculation, array $pagecache = [], ?callable $page = null): string
    {
        Application::getInstance()->applicationInfo['speculation'] = $speculation;
        Application::getInstance()->applicationInfo['pagecache']   = $pagecache;
        Document::reset();
        $doc = new Html();
        if ($page !== null) {
            $page($doc);
        }

        return (string) $doc->render();
    }

    /** The rules from the config: what is included, what is left out, how eager. */
    public function testTheRulesFromTheConfig(): void
    {
        // Act
        $html = $this->render(['prefetch' => ['include' => ['/jobs/*'], 'exclude' => ['/account/*'], 'eagerness' => 'eager'], 'prerender' => ['eagerness' => 'sideways']],
            ['bypassPaths' => ['/basket/*', '#^/api#']]);
        $json  = Document::speculationJson();
        $rules = json_decode($json, true);
        $and   = $rules['prefetch'][0]['where']['and'];

        // Assert
        $this->assertStringContainsString('<script type="speculationrules" data-pramnos-hashed>' . $json . '</script>', $html);
        $this->assertSame([['href_matches' => '/jobs/*']], $and[0]['or']);
        $excluded = array_map(static fn (array $c): string => (string) ($c['not']['href_matches'] ?? $c['not']['selector_matches']), array_slice($and, 1));
        foreach (['*logout*', '*\\?*format=*', '[rel~=nofollow]', '.no-prefetch', '/basket/*', '/account/*'] as $out) {
            $this->assertContains($out, $excluded, $out);
        }
        $this->assertNotContains('#^/api#', $excluded, 'a regular expression is not a URL pattern');
        $this->assertSame('eager', $rules['prefetch'][0]['eagerness']);
        $this->assertSame('moderate', $rules['prerender'][0]['eagerness'], 'not one of the four: moderate');
        $this->assertSame([['href_matches' => '/*']], $rules['prerender'][0]['where']['and'][0]['or'], 'every page unless said');
    }

    /**
     * Hashed in the policy, so the page carries no nonce for it; nothing without config; a path
     * holding `</script>` cannot end the script; a page's own rule carries the nonce.
     */
    public function testHashedNothingEscapedAndThePagesOwn(): void
    {
        // Act
        $none   = $this->render([]);
        $tricky = $this->render(['prefetch' => ['include' => ['/x</script><script>alert(1)']]]);
        $policy = Application::buildCspPolicy([], 'abc');
        $hash   = "'sha256-" . base64_encode(hash('sha256', Document::speculationJson(), true)) . "'";
        $own    = $this->render([], [], static fn (Html $doc) => $doc->addSpeculationRule('prefetch', ['source' => 'list', 'urls' => ['/next']])
            ->addSpeculationRule('nonsense', ['urls' => ['/x']]));

        // Assert
        $this->assertStringNotContainsString('speculationrules', $none);
        $this->assertStringNotContainsString('</script><script>alert', $tricky);
        $this->assertStringContainsString($hash, $policy, 'the configured rules are allowed by their hash');
        $this->assertStringContainsString('<script type="speculationrules">{"prefetch":[{"source":"list","urls":["/next"]}]}</script>', $own, 'a page\'s own: no hash marker, the nonce applies');
        $this->assertStringNotContainsString('nonsense', $own);
    }
}
