<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Html;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The admin screens a browser's AI agent can use (WebMCP): each offers its form as a tool, in
 * every theme, under one name.
 *
 * `pf-utils.js` registers a form marked `data-pf-tool`; a theme whose copy of the view lost the
 * mark would offer the agent nothing on that theme, silently, and a tool named differently per
 * theme is a different tool to an agent that learnt the other name.
 */
#[CoversNothing]
final class AgentToolFormsTest extends TestCase
{
    /**
     * Each view and the tool it offers.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function tools(): array
    {
        $rows = [];
        foreach (['tailwind', 'bootstrap', 'plain-css'] as $theme) {
            foreach ([
                'help/help'                 => 'search_help',
                'tokens/tokens'             => 'list_api_tokens',
                'tokenactions/tokenactions' => 'list_token_actions',
                'mailinglists/mailinglists' => 'find_list_subscribers',
                'scheduler/scheduler'       => 'run_scheduled_task',
            ] as $view => $tool) {
                $rows[$theme . ' ' . $view] = [$theme, $view, $tool];
            }
        }

        return $rows;
    }

    /**
     * The view has the form, marked with the tool's name and a description for the agent.
     */
    #[DataProvider('tools')]
    public function testTheViewOffersItsTool(string $theme, string $view, string $tool): void
    {
        // Arrange
        $file = dirname(__DIR__, 3) . '/scaffolding/themes/' . $theme . '/views/' . $view . '.html.php';

        // Act — the markup only: PHP inside an attribute carries a `>` of its own
        $source = (string) preg_replace('/<\?(?:php|=).*?\?>/s', 'x', (string) file_get_contents($file));

        // Assert
        $this->assertMatchesRegularExpression(
            '/<form [^>]*data-pf-tool="' . preg_quote($tool, '/') . '" data-pf-tool-description="[^"]{20,}"/',
            $source,
            $theme . ' ' . $view . ' does not offer ' . $tool
        );
    }
}
