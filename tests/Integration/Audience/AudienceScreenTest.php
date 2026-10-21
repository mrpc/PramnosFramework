<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Audience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controllers\Audience;
use Pramnos\Audience\Attribute;
use Pramnos\Audience\Attributes;
use Pramnos\Audience\ConsentLists;
use Pramnos\Audience\Segments;
use Pramnos\Html\AudienceBuilder;
use Pramnos\User\User;

/**
 * What the builder asks `/admin/Audience`: the live numbers, a breakdown, a sample with links,
 * why one person is in or out, and the catalogue — each behind the administration floor, and
 * never about an attribute the account may not see.
 */
#[CoversClass(Audience::class)]
#[CoversClass(AudienceBuilder::class)]
class AudienceScreenTest extends TestCase
{
    use AudienceProbe {
        tearDown as private probeTearDown;
    }

    /** Sign out and clear the request, then the probe's own tear-down. */
    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST    = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        if (Application::getInstance()) {
            Application::getInstance()->currentUser = null;
            unset(Application::getInstance()->applicationInfo['audience']);
        }
        $this->probeTearDown();
    }

    /** An administrator — or, with a low type, somebody below the floor — signed in. */
    private function signIn(int $usertype = 98): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = ['logged' => true, 'login' => true, 'userid' => 2, 'uid' => 2, 'usertype' => $usertype];
        $user = new User(0);
        $user->userid   = 2;
        $user->usertype = $usertype;
        $user->username = 'admin';
        Application::getInstance()->currentUser = $user;
    }

    /** The controller, with a redirect that records instead of leaving. */
    private function controller(): Audience
    {
        return new class (Application::getInstance()) extends Audience {
            /** @var list<string> */
            public array $redirects = [];

            /** Record the redirect a refusal makes. */
            public function redirect($url = null, $quit = true, $code = '302')
            {
                $this->redirects[] = (string) $url;
            }
        };
    }

    /**
     * POST to an action as an administrator and read the JSON.
     *
     * @param  array<string, string> $data
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function post(string $action, array $data): array
    {
        $this->signIn();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $data;
        $response = $this->controller()->{$action}();

        return [$response->getStatusCode(), (array) json_decode((string) $response->getBody(), true)];
    }

    /**
     * A filter's preview is its count, a sample linking to each person's card — the application's
     * card where it says where that is — and the breakdown asked for.
     */
    #[DataProvider('databases')]
    public function testAFiltersPreviewIsItsCountSampleAndBreakdown(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Application::getInstance()->applicationInfo['audience'] = ['person_url' => '/admin/People/{id}'];
        $filter = json_encode(['conditions' => [['attribute' => 'language', 'op' => 'is', 'value' => 'el']]]);

        // Act
        [$status, $answer] = $this->post('preview', ['filter' => $filter, 'sample' => '1', 'breakdown' => 'usertype']);

        // Assert
        $this->assertSame(200, $status);
        $this->assertSame(2, $answer['count']);
        $this->assertSame([['id' => $this->ids['cara'], 'name' => 'Cara', 'url' => '/admin/People/' . $this->ids['cara']]], $answer['sample']);
        $this->assertSame([1, 1], array_column($answer['breakdown'], 'count'));
    }

    /** A picker's preview is what it picked, what each step took out, and a sample of who it reaches. */
    #[DataProvider('databases')]
    public function testAPickersPreviewSaysWhatEachStepTookOut(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $picker = json_encode(['include' => [['group' => $this->groups['Volunteers']]], 'exclude' => [['group' => $this->groups['Press']]]]);

        // Act
        [$status, $answer] = $this->post('preview', ['picker' => $picker, 'sample' => '99']);

        // Assert
        $this->assertSame(200, $status);
        $this->assertSame(['included' => 2, 'excluded' => 1, 'consent' => 0, 'final' => 1], array_diff_key($answer, ['sample' => 1]));
        $this->assertSame(adminUrl('Users/view/') . $this->ids['anna'], $answer['sample'][0]['url'], 'the framework\'s card when the application names none');
    }

    /**
     * A filter that does not check, or asks about an attribute this account may not see, is
     * answered 422 with what is wrong — the builder shows it beside the count.
     */
    #[DataProvider('databases')]
    public function testAMalformedOrHiddenFilterIsRefused(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Attributes::define(Attribute::column('revenue', 'usertype', Attribute::NUMBER)->ability('audience.test-never-granted'));
        $hidden = json_encode(['conditions' => [['match' => 'any', 'conditions' => [['attribute' => 'revenue', 'op' => 'gt', 'value' => 1]]]]]);

        // Act
        $answers = [
            $this->post('preview', ['filter' => '{nope']),
            $this->post('preview', ['filter' => $hidden]),
            $this->post('preview', ['filter' => '', 'breakdown' => 'revenue']),
            $this->post('preview', ['picker' => json_encode(['filter' => json_decode($hidden, true)])]),
            $this->post('explain', ['filter' => $hidden, 'person' => (string) $this->ids['anna']]),
            $this->post('explain', ['filter' => '', 'person' => '0']),
        ];

        // Assert
        $this->assertSame([422, 422, 422, 422, 422, 422], array_column($answers, 0));
        $this->assertSame('The filter is not valid JSON.', $answers[0][1]['error']);
        $this->assertSame('You may not filter by revenue.', $answers[1][1]['error']);
        $this->assertSame('Which person? Give an account id.', $answers[5][1]['error']);
    }

    /** explain answers for one person, condition by condition. */
    #[DataProvider('databases')]
    public function testExplainAnswersForOnePerson(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $filter = json_encode(['conditions' => [['attribute' => 'language', 'op' => 'is', 'value' => 'el']]]);

        // Act
        [$status, $why] = $this->post('explain', ['filter' => $filter, 'person' => (string) $this->ids['bob']]);

        // Assert
        $this->assertSame(200, $status);
        $this->assertFalse($why['ok']);
        $this->assertSame('en', $why['conditions'][0]['actual']);
    }

    /**
     * The catalogue is what this account may use: visible attributes with their operators, the
     * operators' words, segments, groups and consent lists; and the builder writes it into the
     * page with the field's state, the theme's classes and its script.
     */
    #[DataProvider('databases')]
    public function testTheCatalogueAndTheMarkupCarryWhatTheBuilderDraws(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Attributes::define(Attribute::column('revenue', 'usertype', Attribute::NUMBER)->ability('audience.test-never-granted'));
        $segment = Segments::create('Greek', ['conditions' => [['attribute' => 'language', 'op' => 'is', 'value' => 'el']]]);
        ConsentLists::create('Newsletter', 'email', 'everyone');
        ConsentLists::create('Push news', 'push', 'everyone');
        $this->signIn();
        $_SERVER['REQUEST_METHOD'] = 'GET';

        // Act
        $catalog = (array) json_decode((string) $this->controller()->catalog()->getBody(), true);
        $filter  = AudienceBuilder::filter('filter', ['conditions' => [['segment' => $segment, 'op' => 'in']]], ['sample' => 7]);
        $picker  = AudienceBuilder::picker('audience', '{"consent":1}', ['channel' => 'email', 'live' => false]);

        // Assert
        $keys = array_column($catalog['attributes'], 'key');
        $this->assertContains('lastlogin', $keys);
        $this->assertNotContains('revenue', $keys, 'behind an ability this account does not hold');
        $this->assertSame('is in segment', $catalog['operators']['in']);
        $this->assertSame([['id' => $segment, 'name' => 'Greek', 'members' => 0]], $catalog['segments']);
        $this->assertSame(['Press', 'Volunteers'], array_column($catalog['groups'], 'name'));
        $this->assertCount(2, $catalog['consent']);
        $this->assertStringContainsString('data-pf-audience="filter"', $filter);
        $this->assertStringContainsString('data-sample="7"', $filter);
        $this->assertStringContainsString('data-breakdown', $filter);
        $this->assertStringContainsString('name="filter" value="{&quot;conditions&quot;:[{&quot;segment&quot;:' . $segment, $filter);
        $this->assertStringContainsString('pf-audience.js', $filter);
        $this->assertStringContainsString('data-pf-audience-live', $filter);
        $this->assertStringContainsString('data-pf-audience="picker"', $picker);
        $this->assertStringNotContainsString('data-pf-audience-live', $picker, 'live off');
        $this->assertStringContainsString('Newsletter', html_entity_decode($picker));
        $this->assertStringNotContainsString('Push news', html_entity_decode($picker), 'only the channel\'s lists');
    }

    /** Below the administration floor every action is refused before it reads anything. */
    #[DataProvider('databases')]
    public function testEveryActionIsRefusedBelowTheFloor(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->signIn(1);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['filter' => '', 'person' => '2'];
        $controller = $this->controller();

        // Act
        $answers = [$controller->catalog(), $controller->preview(), $controller->explain()];

        // Assert
        $this->assertSame([null, null, null], $answers);
        $this->assertCount(3, $controller->redirects);
    }
}
