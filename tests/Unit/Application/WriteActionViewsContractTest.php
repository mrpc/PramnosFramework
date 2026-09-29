<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;

/**
 * No bundled view reaches a write action by a link, or by a form without the token.
 *
 * `Controller::addWriteAction()` makes `exec()` refuse a declared action unless it is a `POST`
 * with the session's token. A view that still links to one (`<a href="…/delete/5">`) is a button
 * that answers "could not be verified", and a form without the token the same — so the guard and
 * the views have to agree, and this holds them to it. The declarations are read from the
 * controllers themselves, so a new write action is covered the day it is declared.
 *
 * Three spellings of an address are recognised, because the views use all three:
 * `adminUrl('users/delete/')`, `adminUrl('Logs' . '/clearFile/')`, and a plain path.
 */
class WriteActionViewsContractTest extends TestCase
{
    /** @return array<string, list<string>> controller URL segment → its declared write actions */
    private function declaredWrites(): array
    {
        $root   = dirname(__DIR__, 3) . '/src/Pramnos';
        $writes = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if (!str_ends_with((string) $file, '.php') || !str_contains((string) $file, '/Controllers/')) {
                continue;
            }
            $source = (string) file_get_contents((string) $file);
            if (!preg_match('/addWriteAction\(\[([^\]]*)\]\)/', $source, $m)) {
                continue;
            }
            $segment = strtolower(preg_replace('/Controller$/', '', basename((string) $file, '.php')));
            preg_match_all("/'([A-Za-z]+)'/", $m[1], $actions);
            $writes[$segment] = array_map('strtolower', $actions[1]);
        }

        return $writes;
    }

    private function namesAWrite(string $address, array $writes): bool
    {
        $address = (string) preg_replace("/'\\s*\\.\\s*'/", '', $address);
        preg_match_all('#(\w+)/(\w+)#', $address, $pairs, PREG_SET_ORDER);

        foreach ($pairs as [, $controller, $action]) {
            if (in_array(strtolower($action), $writes[strtolower($controller)] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every link and every form in every bundled theme's views, against the declarations.
     */
    public function testNoViewLinksToAWriteActionOrPostsToOneWithoutTheToken(): void
    {
        // Arrange
        $writes = $this->declaredWrites();
        $this->assertArrayHasKey('users', $writes, 'the declarations were not found');
        $views  = dirname(__DIR__, 3) . '/scaffolding/themes';
        $found  = [];

        // Act
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($views)) as $file) {
            if (!str_ends_with((string) $file, '.php') || !str_contains((string) $file, '/views/')) {
                continue;
            }
            $source = (string) file_get_contents((string) $file);
            $where  = str_replace($views . '/', '', (string) $file);

            // A link, whatever builds its address: a closing PHP tag inside it does not end it.
            preg_match_all('/<a\b(?:[^>"]|"[^"]*")*>/s', $source, $links);
            foreach ($links[0] as $link) {
                if (str_contains($link, 'href') && $this->namesAWrite($link, $writes)) {
                    $found[] = $where . ': a link to a write action';
                }
            }
            preg_match_all('/Icon::link\((.{0,160})/s', $source, $icons);
            foreach ($icons[1] as $arguments) {
                if ($this->namesAWrite(explode(',', $arguments)[0], $writes)) {
                    $found[] = $where . ': Icon::link() to a write action';
                }
            }

            // A form posting to one, and the token somewhere before it closes.
            preg_match_all('#<form\b((?:[^>"]|"[^"]*")*)>(.*?)</form>#s', $source, $forms, PREG_SET_ORDER);
            foreach ($forms as [, $attributes, $body]) {
                if (stripos($attributes, 'post') !== false && $this->namesAWrite($attributes, $writes)
                    && !preg_match('/getTokenField|tokenField\(|_csrf_token|\$csrf\b/', $body)) {
                    $found[] = $where . ': a form to a write action without the token';
                }
            }
        }

        // Assert
        $this->assertSame([], $found, 'Render these with Icon::postButton()/postControl(), or give the form the token.');
    }
}
