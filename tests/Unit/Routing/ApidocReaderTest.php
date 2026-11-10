<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Routing\ApidocReader;
use Pramnos\Routing\OpenApiGenerator;
use Pramnos\Routing\Route;

/**
 * apidoc's `@api` blocks in an application's controllers describe, in the API's document, the
 * routes that say nothing of themselves: the title, description and group; the parameters in the
 * path under the route's own names, in the query or the body; what success answers and the
 * errors. A route's own `describe()` wins over a block.
 */
#[CoversClass(ApidocReader::class)]
#[CoversClass(OpenApiGenerator::class)]
class ApidocReaderTest extends TestCase
{
    /** A scratch controllers directory. */
    private string $dir = '';

    /** Two controllers documented with apidoc. */
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pf-apidoc-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/Me', 0777, true);
        file_put_contents($this->dir . '/X.php', <<<'PHP'
<?php
class X {
    /**
     * @api {get} 1.0/x/:id Get X
     * @apiGroup Things
     * @apiDescription One x,
     *   if it is yours.
     * @apiParam {Number} id The x
     * @apiParam {Boolean} [full=false] Everything about it
     * @apiSuccess {String} name Its name
     * @apiSuccess {Object[]} parts Its parts
     * @apiError NotFound No such x
     * @apiError (403) {Object} Forbidden Not yours
     */
    public function get() {}
    /**
     * @api {post} 1.0/x Make X
     * @apiParam {String} name Its name
     * @apiParam {Number} [size] How big
     */
    public function post() {}
    /** Not an apidoc block. */
    public function other() {}
}
PHP);
        file_put_contents($this->dir . '/Me/N.php', "<?php\n/**\n * @api {put} 1.0/me/notifications/:id/read Read it\n */\nfunction n() {}\n");
        file_put_contents($this->dir . '/readme.txt', '@api {get} /nothing Not PHP');
    }

    /** The scratch directory gone. */
    protected function tearDown(): void
    {
        foreach (['/X.php', '/Me/N.php', '/readme.txt'] as $file) {
            @unlink($this->dir . $file);
        }
        @rmdir($this->dir . '/Me');
        @rmdir($this->dir);
    }

    /**
     * `@api {get} 1.0/x/:id Get X` describes the route `/x/{xid}`: its summary, its group as the
     * tag, its description, `id` as the route's `xid` in the path, `full` an optional query
     * parameter, the 200's fields and a 4XX naming the errors. A POST's parameters are its body.
     * A route that describes itself keeps its own.
     */
    public function testTheBlocksDescribeTheRoutes(): void
    {
        // Arrange
        $own = (new Route('/me/notifications/{nid}/read', 'PUT', static fn () => null))->describe('Its own words');
        $routes = [
            'GET'  => ['/1.0/x/{xid}' => ['hasPermissions' => false]],
            'POST' => ['/1.0/x' => []],
            'PUT'  => ['/1.0/me/notifications/{nid}/read' => ['doc' => $own->getDoc()]],
        ];

        // Act
        $read = ApidocReader::read($this->dir);
        $doc  = (new OpenApiGenerator())->withApidoc($read)->fromRoutes($routes);
        $get  = $doc['paths']['/1.0/x/{xid}']['get'];
        $post = $doc['paths']['/1.0/x']['post'];

        // Assert
        $keys = array_keys($read);
        sort($keys);
        $this->assertSame(['get /x/{}', 'post /x', 'put /me/notifications/{}/read'], $keys, 'the PHP files only, every block');
        $this->assertSame(['Get X', ['Things'], "One x,\nif it is yours."], [$get['summary'], $get['tags'], $get['description']]);
        $this->assertSame(['xid', 'path', 'number', 'The x'], [$get['parameters'][0]['name'], $get['parameters'][0]['in'], $get['parameters'][0]['schema']['type'], $get['parameters'][0]['description']]);
        $this->assertSame(['full', 'query', false, 'boolean'], [$get['parameters'][1]['name'], $get['parameters'][1]['in'], $get['parameters'][1]['required'], $get['parameters'][1]['schema']['type']]);
        $this->assertSame(['name' => 'string', 'parts' => 'array'], array_map(static fn (array $p): string => $p['type'],
            $get['responses']['200']['content']['application/json']['schema']['properties']));
        $this->assertSame("NotFound: No such x\nForbidden: Not yours", $get['responses']['4XX']['description']);
        $this->assertSame(['name'], $post['requestBody']['content']['application/json']['schema']['required'], 'size is optional');
        $this->assertSame('Its own words', $doc['paths']['/1.0/me/notifications/{nid}/read']['put']['summary'], 'describe() wins');
        $this->assertSame([], ApidocReader::read($this->dir . '/none'));
        $this->assertNull(ApidocReader::parse('/** Nothing here. */'));
    }
}
