<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Application\FeatureRegistry;
use Pramnos\Audience\Rules\Actions;
use Pramnos\Audience\Rules\Context;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

/**
 * A rule's step that asks the AI about the person — on MySQL and PostgreSQL.
 *
 * What holds: offered only where the AI feature is on; the question is filled for the person and
 * carries what started the run; the analyst answers as the administrator the step names, and the
 * answer goes to that administrator or into an attribute; a rule tried in shadow, with no provider,
 * no administrator or no question asks nothing.
 */
#[CoversClass(Actions::class)]
class RuleAiActionTest extends LlmTestCase
{
    use ChatDatabase;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        FeatureRegistry::reset();
        FeatureRegistry::loadFromConfig(['llm']);
    }

    protected function tearDown(): void
    {
        if ($this->ids !== []) {
            $this->db->queryBuilder()->table('#PREFIX#users')->whereIn('userid', array_values($this->ids))->delete();
        }
        FeatureRegistry::reset();
        $this->disconnectDatabase();
        parent::tearDown();
    }

    private function context(bool $shadow = false): Context
    {
        return new Context(1, 'Welcome flow', 1, 'ask', 7, $this->ids['person'], ['event' => 'user.signed_up'], null, $shadow);
    }

    /** An administrator and a person, made for the test. */
    private function people(): void
    {
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\Auth\CreateUserdetailsTable::class], $this->db);
        $mark = 'ra' . bin2hex(random_bytes(3));
        foreach (['admin' => 99, 'person' => 1] as $who => $usertype) {
            $this->db->queryBuilder()->table('#PREFIX#users')->insert(['username' => $mark . $who, 'email' => $mark . $who . '@example.com',
                'password' => 'x', 'regdate' => time(), 'usertype' => $usertype, 'firstname' => ucfirst($who)]);
            $this->ids[$who] = (int) $this->db->getInsertId();
        }
        \Pramnos\User\User::clearUserCache();
        $this->db->cacheflush('userlist');
    }

    /**
     * Asked and answered as the administrator; the answer sent to them; and every way it asks
     * nothing.
     */
    #[DataProvider('databases')]
    public function testItAsksAndTellsTheAdministrator(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->people();
        $run = Actions::get('ai')['run'];
        $params = ['to' => (string) $this->ids['admin'], 'prompt' => 'Is {firstname} likely to buy? One word.', 'then' => 'notify'];

        // Act — no provider yet, then with one
        $noProvider = $run($this->ids['person'], $params, $this->context());
        $this->setting('llm_openai_api_key', 'sk-test');
        $this->answer([[200, self::says('Likely.')]]);
        $done    = $run($this->ids['person'], $params, $this->context());
        $shadow  = $run($this->ids['person'], $params, $this->context(true));
        $nobody  = $run($this->ids['person'], ['to' => '424242'] + $params, $this->context());
        $empty   = $run($this->ids['person'], ['prompt' => '  '] + $params, $this->context());
        $this->answer([[500, []]]);
        $failed  = $run($this->ids['person'], $params, $this->context());

        // Assert
        $this->assertSame(['status' => 'skipped', 'detail' => ['reason' => 'no provider']], $noProvider);
        $this->assertSame('done', $done['status']);
        $this->assertSame([$this->ids['admin'], 'Likely.'], [$done['detail']['to'], $done['detail']['answer']]);
        $asked = (string) end($this->sent[0]['body']['messages'])['content'];
        $this->assertStringContainsString('Is Person likely to buy? One word.', $asked, 'filled for the person');
        $this->assertStringContainsString('user.signed_up', $asked, 'with what started the run');
        $this->assertSame(['status' => 'skipped', 'detail' => ['reason' => 'shadow']], $shadow, 'a rule being tried does not spend');
        $this->assertSame('error', $nobody['status']);
        $this->assertSame(['status' => 'error', 'detail' => ['reason' => 'nothing to ask']], $empty);
        $this->assertSame('error', $failed['status']);
        // The answered one, and the failed one asked three times: a 500 is asked again twice (Client::RETRIES)
        $this->assertCount(1 + 1 + count(\Pramnos\Llm\Client::RETRIES), $this->sent, 'only the two that asked');
    }

    /** The answer kept in an attribute, for a classification. */
    #[DataProvider('databases')]
    public function testItKeepsTheAnswerInAnAttribute(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->people();
        $this->setting('llm_openai_api_key', 'sk-test');
        \Pramnos\Audience\Attributes::reset();
        \Pramnos\Audience\Attributes::define(\Pramnos\Audience\Attribute::column('lastname', 'lastname', \Pramnos\Audience\Attribute::TEXT));
        $this->answer([[200, self::says('Warm')]]);

        // Act
        $kept = (Actions::get('ai')['run'])($this->ids['person'], ['to' => (string) $this->ids['admin'], 'prompt' => 'Warm or cold?', 'then' => 'attribute', 'attribute' => 'lastname'], $this->context());

        // Assert
        $this->assertSame('done', $kept['status']);
        $this->assertSame('Warm', $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', $this->ids['person'])->first()->fields['lastname']);
        \Pramnos\Audience\Attributes::reset();
    }

    /** Not offered where the AI feature is off. */
    public function testNotOfferedWithoutTheFeature(): void
    {
        // Arrange
        FeatureRegistry::reset();
        FeatureRegistry::loadFromConfig([]);

        // Act / Assert
        $this->assertNull(Actions::get('ai'));
    }
}
