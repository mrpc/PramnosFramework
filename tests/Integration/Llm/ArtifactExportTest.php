<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Dashboard\Widgets\PinnedArtifacts;
use Pramnos\Llm\Chat\Artifacts;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Chat\Web\ArtifactTools;
use Pramnos\Llm\Chat\Web\ArtifactView;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

require_once __DIR__ . '/ArtifactsTest.php';

/**
 * Charts, CSV, a page to print, and pinning to the dashboard — on MySQL and PostgreSQL.
 *
 * What holds: a chart is checked before it is kept and drawn with the page's charts; a table, a
 * list and a chart come out as CSV that a spreadsheet opens and cannot be made to run a formula; a
 * text has no CSV; an artifact opens on a page of its own to print; a person pins only their own,
 * within a limit, sees them on the dashboard card, and takes them off.
 */
#[CoversClass(Artifacts::class)]
#[CoversClass(ArtifactTools::class)]
#[CoversClass(PinnedArtifacts::class)]
class ArtifactExportTest extends LlmTestCase
{
    use ChatDatabase;

    /** @var list<int> */
    private array $users = [];

    protected function tearDown(): void
    {
        if ($this->users !== []) {
            $this->db->queryBuilder()->table('#PREFIX#usersettings')->whereIn('userid', $this->users)->delete();
            $this->db->queryBuilder()->table('#PREFIX#users')->whereIn('userid', $this->users)->delete();
        }
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** A user for pins, which are kept as their setting. */
    private function user(): int
    {
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\Core\CreateUsersettingsTable::class, \Pramnos\Framework\Migrations\Auth\CreateUserdetailsTable::class], $this->db);
        $mark = 'pn' . bin2hex(random_bytes(4));
        $this->db->queryBuilder()->table('#PREFIX#users')->insert(['username' => $mark, 'email' => $mark . '@example.com', 'password' => 'x', 'regdate' => time()]);
        $id = (int) $this->db->getInsertId();
        $this->users[] = $id;
        \Pramnos\User\User::clearUserCache();
        $this->db->cacheflush('userlist');

        return $id;
    }

    /** A chart: checked, kept, drawn; and each kind as CSV, a formula disarmed. */
    #[DataProvider('databases')]
    public function testChartsAndCsv(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $artifacts = new Artifacts(new Scope(7, 3));

        // Act
        $refusals = [
            $artifacts->create(['kind' => 'chart', 'title' => 'C', 'chart_type' => 'radar', 'labels' => ['a'], 'series' => [['label' => 's', 'data' => [1]]]]),
            $artifacts->create(['kind' => 'chart', 'title' => 'C', 'labels' => [], 'series' => [['label' => 's', 'data' => []]]]),
            $artifacts->create(['kind' => 'chart', 'title' => 'C', 'labels' => ['a', 'b'], 'series' => [['label' => 's', 'data' => [1]]]]),
            $artifacts->create(['kind' => 'chart', 'title' => 'C', 'labels' => ['a'], 'series' => [['label' => 's', 'data' => ['1']]]]),
            $artifacts->create(['kind' => 'chart', 'title' => 'C', 'labels' => ['a'], 'series' => []]),
        ];
        $chart = $artifacts->create(['kind' => 'chart', 'title' => 'Bookings', 'chart_type' => 'line', 'labels' => ['May', 'June'],
            'series' => [['label' => '2025', 'data' => [3, 4.5]], ['label' => '2026', 'data' => [5, 6]]]]);
        $table = $artifacts->create(['kind' => 'table', 'title' => 'T', 'columns' => ['Name', 'Note'], 'rows' => [['=HYPERLINK("x")', -3], ['Maria', true]]]);
        $list  = $artifacts->create(['kind' => 'list', 'title' => 'L', 'entity' => 'user', 'ids' => [4, 5], 'labels' => ['Maria', '@nikos']]);
        $text  = $artifacts->create(['kind' => 'text', 'title' => 'X', 'text' => 'x']);

        // Assert
        foreach ($refusals as $i => $refusal) {
            $this->assertIsString($refusal, 'case ' . $i);
        }
        $this->assertSame(['chart' => 'line', 'labels' => ['May', 'June'], 'series' => [['label' => '2025', 'data' => [3, 4.5]], ['label' => '2026', 'data' => [5, 6]]]], $chart['content']);
        $this->assertStringContainsString('data-pf-chart-figure', ArtifactView::html($chart), 'drawn with the page\'s charts');
        $this->assertSame("\xEF\xBB\xBF,2025,2026\nMay,3,5\nJune,4.5,6\n", Artifacts::csv($chart));
        $this->assertSame("\xEF\xBB\xBFName,Note\n\"'=HYPERLINK(\"\"x\"\")\",-3\nMaria,true\n", Artifacts::csv($table), 'a formula is text; a negative number stays a number');
        $this->assertSame("\xEF\xBB\xBFid,label\n4,Maria\n5,'@nikos\n", Artifacts::csv($list));
        $this->assertNull(Artifacts::csv($text));
        $this->assertSame('Bookings-' . $chart['artifact_id'] . '.csv', Artifacts::filename($chart, 'csv'));
        $this->assertSame('artifact-0.csv', Artifacts::filename(['title' => '!!!'], 'csv'));
    }

    /** Pinning: one's own, within the limit; shown on the card; taken off. And the page's buttons and actions. */
    #[DataProvider('databases')]
    public function testPinningAndThePage(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $userId    = $this->user();
        $scope     = new Scope($userId, null);
        $artifacts = new Artifacts($scope);
        $table     = $artifacts->create(['kind' => 'table', 'title' => 'Pinned table', 'columns' => ['a'], 'rows' => [[1]]]);
        $text      = $artifacts->create(['kind' => 'text', 'title' => 'T', 'text' => 'x']);
        $theirs    = (new Artifacts(new Scope(8, null)))->create(['kind' => 'text', 'title' => 'Theirs', 'text' => 'x']);

        // Act
        $pinned  = $artifacts->pin($table['artifact_id']);
        $again   = $artifacts->pin($table['artifact_id']);
        $missing = $artifacts->pin($theirs['artifact_id']);
        $nobody  = (new Artifacts(new Scope(999999, null)))->pin($table['artifact_id']);
        $user    = new \Pramnos\User\User($userId);
        $ids     = Artifacts::pinnedIds($user);
        $shown   = Artifacts::pinned($user);

        // Assert
        $this->assertNull($pinned);
        $this->assertNull($again, 'pinning twice keeps one');
        $this->assertSame('There is no such artifact.', $missing);
        $this->assertSame('There is nobody to pin it for.', $nobody);
        $this->assertSame([$table['artifact_id']], $ids);
        $this->assertSame('Pinned table', $shown[0]['title']);

        // The buttons under an artifact
        $buttons = ArtifactTools::buttons($table, ['download' => '/d/', 'print' => '/p/', 'pin' => '/pin/'], $ids, 9, '', []);
        $this->assertStringContainsString('href="/d/' . $table['artifact_id'] . '"', $buttons);
        $this->assertStringContainsString('Unpin from dashboard', $buttons);
        $this->assertStringContainsString('name="pinned" value="0"', $buttons);
        $this->assertStringNotContainsString('/d/', ArtifactTools::buttons($text, ['download' => '/d/'], [], 9, '', []), 'a text has no CSV');
        $this->assertStringContainsString('window.print()', ArtifactTools::printable($table));

        // The page's actions
        $page = new PageFixture($scope, 'plain-css');
        $csv  = $page->download($table['artifact_id']);
        $none = new PageFixture($scope, 'plain-css');
        $none->download($text['artifact_id']);
        $print = (string) $page->printable($table['artifact_id']);
        $gone  = new PageFixture($scope, 'plain-css');
        $gone->printable(999999);
        $_POST = ['pinned' => '0', 'conversation_id' => '0'];
        $unpin = new PageFixture($scope, 'plain-css');
        $unpin->pin($table['artifact_id']);
        $_POST = [];
        $this->assertStringStartsWith("\xEF\xBB\xBFa\n1", (string) $csv);
        $this->assertSame(['There is nothing to download here.'], $none->errors);
        $this->assertStringContainsString('Pinned table', $print);
        $this->assertSame(['There is no such artifact.'], $gone->errors);
        $this->assertSame(['Taken off your dashboard.'], $unpin->messages);
        $this->assertSame([], Artifacts::pinnedIds(new \Pramnos\User\User($userId)));

        // The API
        $api = new ArtifactsApi($scope, false, ['pinned' => true]);
        $this->assertSame('a', trim(substr((string) $api->artifactCsv($table['artifact_id'])['data']['csv'], 3, 2)));
        $this->assertSame(404, $api->artifactCsv($text['artifact_id'])['status']);
        $this->assertTrue($api->pinArtifact($table['artifact_id'])['data']['pinned']);
        $this->assertSame(404, $api->pinArtifact($theirs['artifact_id'])['status']);
        $this->assertSame(403, (new ArtifactsApi($scope, true))->pinArtifact($table['artifact_id'])['status']);
        $this->assertSame(403, (new ArtifactsApi($scope, true))->artifactCsv($table['artifact_id'])['status']);
    }

    /** The dashboard card: its name, who it is for, and what it shows. */
    public function testTheDashboardCard(): void
    {
        // Arrange
        $card = new PinnedArtifacts();

        // Act / Assert
        $this->assertSame('ai.pinned', $card->id());
        $this->assertSame('Pinned from Ask', $card->title());
        $this->assertNotSame('', $card->description());
        $this->assertFalse($card->allowed(null));
        $this->assertFalse($card->allowed((object) ['userid' => 1]), 'not for a guest');
        $this->assertStringContainsString('Nothing pinned yet', $card->render(), 'nobody signed in here');
    }
}
