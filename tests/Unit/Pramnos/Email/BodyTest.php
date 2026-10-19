<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Email\Body;

/**
 * A sent message's body read back from its `mails` row: the content column, or the file its path
 * names as applications have kept them — gzipped, relative to the application's root — and
 * nothing a row's path could use to read a file it should not.
 */
#[CoversClass(Body::class)]
#[CoversClass(\Pramnos\Messaging\Mail::class)]
final class BodyTest extends TestCase
{
    private string $dir = '';

    /** Where this test keeps its bodies, inside the application's root as applications do. */
    private string $relative = '';

    protected function setUp(): void
    {
        $this->relative = '/var/pf-bodytest-' . bin2hex(random_bytes(3));
        $this->dir      = ROOT . $this->relative;
        mkdir($this->dir . '/2026/10/19/09', 0777, true);
        file_put_contents($this->dir . '/2026/10/19/09/abc.html.gz', gzencode('<p>Γεια σας</p>'));
        file_put_contents($this->dir . '/plain.html', '<p>plain</p>');
        file_put_contents($this->dir . '/notes.php', '<?php secret();');
    }

    protected function tearDown(): void
    {
        foreach (['/2026/10/19/09/abc.html.gz', '/plain.html', '/notes.php'] as $file) {
            @unlink($this->dir . $file);
        }
        foreach (['/2026/10/19/09', '/2026/10/19', '/2026/10', '/2026', ''] as $sub) {
            @rmdir($this->dir . $sub);
        }
    }

    /**
     * The content column wins where it has a body. Without one, the file the path names is read —
     * gzipped as applications keep it, the path relative to the root with or without its leading
     * slash, or absolute inside the root — and plain HTML too.
     */
    public function testTheBodyIsTheContentOrTheFile(): void
    {
        // Arrange
        $gz = $this->relative . '/2026/10/19/09/abc.html.gz';

        // Act + Assert
        $this->assertSame('<p>in the row</p>', Body::of(['content' => '<p>in the row</p>', 'path' => $gz]));
        $this->assertSame('<p>Γεια σας</p>', Body::of(['content' => '', 'path' => $gz]), 'as an application writes it');
        $this->assertSame('<p>Γεια σας</p>', Body::of(['path' => ltrim($gz, '/')]), 'without the leading slash');
        $this->assertSame('<p>Γεια σας</p>', Body::of(['path' => ROOT . $gz]), 'absolute, inside the root');
        $this->assertSame('<p>plain</p>', Body::of(['path' => $this->relative . '/plain.html']));
    }

    /**
     * A path is data from a row: one that climbs out of the root, names another kind of file,
     * carries a NUL or names nothing reads as no body — never as the file it points at.
     */
    public function testAPathCannotReadAnythingElse(): void
    {
        // Act + Assert
        $this->assertSame('', Body::of(['path' => $this->relative . '/../../../../etc/passwd']), 'out of the root');
        $this->assertSame('', Body::of(['path' => '/etc/hostname']), 'absolute, outside the root');
        $this->assertSame('', Body::of(['path' => $this->relative . '/notes.php']), 'not a body\'s kind of file');
        $this->assertSame('', Body::of(['path' => $this->relative . "/plain.html\0.gz"]));
        $this->assertSame('', Body::of(['path' => $this->relative . '/gone.html.gz']), 'not there any more');
        $this->assertSame('', Body::of([]), 'neither content nor path');
        $this->assertNull(Body::file(''));
    }

    /** The mail model reads its body the same way, and leaves its content as the row keeps it. */
    public function testTheMailModelReadsItsBody(): void
    {
        // Arrange
        $mail = (new \ReflectionClass(\Pramnos\Messaging\Mail::class))->newInstanceWithoutConstructor();
        $mail->content = '';
        $mail->path    = $this->relative . '/2026/10/19/09/abc.html.gz';

        // Act
        $body = $mail->body();

        // Assert
        $this->assertSame('<p>Γεια σας</p>', $body);
        $this->assertSame('', $mail->content, 'read, not written back');
    }
}
