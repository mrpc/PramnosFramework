<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Llm;

use Pramnos\Llm\Connection;
use Pramnos\Llm\Providers;

/**
 * Which provider, which key, which model: the configuration half of the module.
 *
 * These are read from `app.php` and from settings, and every fallback here is one somebody will
 * meet the day a setting holds a provider a later release removed.
 */
class ProvidersAndConnectionTest extends LlmTestCase
{
    /**
     * An application's entries are merged over the built-in ones, and a new one is complete.
     */
    public function testAnApplicationAddsAndOverridesProviders(): void
    {
        // Arrange
        $this->configure(['default' => 'deepseek', 'providers' => [
            'openai' => ['default_model' => 'gpt-4.1-mini'],
            'shared' => ['label' => 'Shared project', 'base_url' => 'https://shared.example/v1'],
            'broken' => 'not an entry',
        ]]);

        // Act
        $all = Providers::all();

        // Assert
        $this->assertSame('gpt-4.1-mini', $all['openai']['default_model']);
        $this->assertSame('max_completion_tokens', $all['openai']['token_param'], 'the rest of the built-in entry stays');
        $this->assertSame('llm_shared_api_key', $all['shared']['settings']['api_key'], 'setting names are derived');
        $this->assertSame('max_tokens', $all['shared']['token_param'], 'defaults fill a new entry');
        $this->assertArrayNotHasKey('broken', $all);
        $this->assertSame('deepseek', Providers::defaultProvider());
    }

    /**
     * Resolution: named, then chosen, then the default — and an unknown name falls back.
     */
    public function testResolutionFallsBackRatherThanFailing(): void
    {
        // Arrange / Act / Assert — the framework's default
        $this->assertSame('openai', Providers::resolve());
        $this->configure(['default' => 'no-such']);
        $this->assertSame('openai', Providers::resolve(), 'an unknown default is ignored');

        // An administrator's choice, then a caller's name over it
        $this->setting('llm_provider', 'mistral');
        $this->assertSame('mistral', Providers::resolve());
        $this->assertSame('gemini', Providers::resolve('gemini'));
        $this->assertSame('mistral', Providers::resolve('removed-later'));

        // An unknown provider's entry is the default's
        $this->assertSame('OpenAI', Providers::config('removed-later')['label']);
        $this->assertSame('llm_gemini_model', Providers::settingKey('gemini', 'model'));
    }

    /**
     * Every setting the module owns is listed, so nothing prints a key by accident.
     */
    public function testEverySettingNameIsListed(): void
    {
        // Arrange
        $this->configure(['features' => ['chat' => 'Ask AI']]);

        // Act
        $keys = Providers::allSettingKeys();

        // Assert
        foreach (['llm_provider', 'llm_prices', 'llm_openai_admin_key', 'llm_deepseek_api_key', 'llm_feature_chat_model'] as $key) {
            $this->assertContains($key, $keys);
        }
    }

    /**
     * Only chat models are offered from a provider's catalogue.
     */
    public function testOnlyChatModelsAreOffered(): void
    {
        // Assert
        $this->assertTrue(Providers::isChatModel('gemini', 'gemini-3.8-flash'));
        $this->assertFalse(Providers::isChatModel('gemini', 'gemini-embedding-001'));
        $this->configure(['providers' => ['any' => ['model_pattern' => null]]]);
        $this->assertTrue(Providers::isChatModel('any', 'whatever'));
    }

    /**
     * A connection reads its provider's settings, and falls back to the entry's defaults.
     */
    public function testAConnectionReadsItsSettings(): void
    {
        // Arrange
        $this->setting('llm_deepseek_api_key', \Pramnos\Security\Encrypter::encrypt('sk-secret'));
        $this->setting('llm_deepseek_base_url', 'https://proxy.example/');
        $this->setting('llm_deepseek_temperature', '0.2');
        $this->setting('llm_deepseek_reasoning', '1');
        $this->setting('llm_timeout', '900');

        // Act
        $deepseek = new Connection('deepseek');
        $openai   = new Connection('openai');

        // Assert — the stored key decrypted, the URL trimmed, the timeout capped
        $this->assertSame('sk-secret', $deepseek->apiKey());
        $this->assertSame('https://proxy.example', $deepseek->baseUrl());
        $this->assertSame(0.2, $deepseek->temperature());
        $this->assertTrue($deepseek->reasoning());
        $this->assertSame(300, $deepseek->timeout());
        $this->assertSame('DeepSeek', $deepseek->label());
        // Defaults where nothing is set
        $this->assertFalse($openai->isConfigured());
        $this->assertSame('https://api.openai.com/v1', $openai->baseUrl());
        $this->assertSame('gpt-4o-mini', $openai->model());
        $this->assertSame(0.7, $openai->temperature());
        $this->assertSame(2000, $openai->maxTokens());
        $this->assertFalse($openai->reasoning());
        $this->setting('llm_timeout', '');
        $this->assertSame(60, $openai->timeout());
    }

    /**
     * A key that cannot be decrypted reads as no key; a plaintext one still works.
     */
    public function testAnUnreadableKeyIsNoKey(): void
    {
        // Arrange
        $this->setting('llm_openai_api_key', 'enc:v1:not-really');
        $this->setting('llm_mistral_api_key', 'sk-plain');

        // Assert
        $this->assertSame('', (new Connection('openai'))->apiKey());
        $this->assertSame('sk-plain', (new Connection('mistral'))->apiKey());
    }

    /**
     * with() replaces fields for one call and leaves the original alone.
     */
    public function testWithReplacesFieldsForOneCall(): void
    {
        // Arrange
        $base = new Connection('openai');

        // Act
        $theirs = $base->with(['api_key' => 'sk-theirs', 'base_url' => 'https://theirs.example', 'model' => 'gpt-4o',
            'temperature' => 0, 'max_tokens' => 9000, 'reasoning' => true, 'own' => true, 'unknown' => 'ignored']);

        // Assert
        $this->assertSame('sk-theirs', $theirs->apiKey());
        $this->assertSame('https://theirs.example', $theirs->baseUrl());
        $this->assertSame('gpt-4o', $theirs->model());
        $this->assertSame(0.0, $theirs->temperature());
        $this->assertSame(9000, $theirs->maxTokens());
        $this->assertTrue($theirs->reasoning());
        $this->assertTrue($theirs->ownKey());
        $this->assertFalse($base->ownKey(), 'the original is untouched');
        $this->assertSame('', $base->apiKey());
    }

    /**
     * A feature's settings apply, follow another feature when empty, and a model set for one
     * provider is not asked of another.
     */
    public function testFeaturesHaveTheirOwnSettings(): void
    {
        // Arrange
        $this->configure(['features' => ['chat', 'writer' => 'The writer', 'loop' => 'Loops'], 'follows' => ['writer' => 'chat', 'loop' => 'loop']]);
        $this->setting('llm_feature_chat_provider', 'deepseek');
        $this->setting('llm_feature_chat_model', 'deepseek-reasoner');
        $this->setting('llm_feature_chat_max_tokens', '6000');
        $this->setting('llm_feature_chat_reasoning', '1');
        $this->setting('llm_feature_chat_temperature', '0.1');

        // Act
        $writer = new Connection(null, 'writer');
        $moved  = $writer->onProvider('openai');

        // Assert
        $this->assertSame(['chat' => 'chat', 'writer' => 'The writer', 'loop' => 'Loops'], Connection::features());
        $this->assertSame('deepseek', $writer->provider(), 'followed from chat');
        $this->assertSame('deepseek-reasoner', $writer->model());
        $this->assertSame(6000, $writer->maxTokens());
        $this->assertTrue($writer->reasoning());
        $this->assertSame(0.1, $writer->temperature());
        $this->assertSame('writer', $writer->feature());
        $this->assertSame('gpt-4o-mini', $moved->model(), 'DeepSeek\'s model is not asked of OpenAI');
        $this->assertFalse($moved->reasoning());
        $this->assertSame(6000, $moved->maxTokens(), 'a budget still applies');
        $this->assertSame('', Connection::featureValue('loop', 'model'), 'a loop stops');
        $this->assertSame('', Connection::featureValue('undeclared', 'model'));
    }

    /**
     * The model list is read from the provider, filtered, and kept; a failed read falls back.
     */
    public function testModelsComeFromTheProvider(): void
    {
        // Arrange
        $this->setting('llm_gemini_api_key', 'sk-g');
        $this->answer([[200, ['data' => [['id' => 'gemini-3.8-flash'], ['id' => 'gemini-embedding-001'], ['id' => 'gemini-9']]]]]);
        $gemini = new Connection('gemini');

        // Act
        $models = $gemini->models();
        $gemini->models();

        // Assert
        $this->assertSame(['gemini-3.8-flash' => 'Gemini 3.8 Flash — stronger, three times the price', 'gemini-9' => 'gemini-9'], $models);
        $this->assertCount(1, $this->sent, 'the second read was kept');

        // A failure falls back to the curated list, and is not kept
        $this->answer([[500, []]]);
        $this->assertArrayHasKey('gemini-3.1-flash-lite', $gemini->models(true));
        $this->assertArrayHasKey('mistral-small-2603', (new Connection('mistral'))->models(), 'unconfigured: curated');
    }

    /**
     * The balance where a provider publishes one, and what it says about credit.
     */
    public function testTheBalanceAndCredit(): void
    {
        // Arrange
        $this->setting('llm_deepseek_api_key', 'sk-d');
        $deepseek = new Connection('deepseek');

        // Act / Assert — available
        $this->answer([[200, ['is_available' => true, 'balance_infos' => [['total_balance' => '3.20']]]]]);
        $this->assertTrue($deepseek->supportsBalance());
        $this->assertTrue($deepseek->hasCredit());

        // An empty account, by the figures
        $this->answer([[200, ['balance_infos' => [['total_balance' => '0']]]]]);
        Connection::forget();
        $this->assertFalse($deepseek->hasCredit());
        $this->answer([[200, ['balance_infos' => [['total_balance' => '1']]]]]);
        Connection::forget();
        $this->assertTrue($deepseek->hasCredit());

        // Nobody can tell
        $this->answer([[500, []]]);
        Connection::forget();
        $this->assertNull($deepseek->hasCredit());
        $this->assertFalse((new Connection('openai'))->supportsBalance());
        $this->assertNull((new Connection('openai'))->balance());
    }

    /**
     * check() says what is wrong in words.
     */
    public function testCheckSaysWhatIsWrong(): void
    {
        // Arrange
        $this->setting('llm_openai_api_key', 'sk-o');
        $openai = new Connection('openai');
        $cases  = [
            [200, [], 'OpenAI answered. The key works.'],
            [401, [], 'OpenAI refused the key (HTTP 401). Check it has not been revoked, and that it is this provider\'s.'],
            [404, ['error' => ['message' => 'gone']], 'OpenAI answered HTTP 404. gone'],
            [418, [], 'OpenAI answered HTTP 418. No reason given.'],
        ];

        foreach ($cases as [$status, $body, $says]) {
            $this->answer([[$status, $body]]);

            // Act / Assert
            $this->assertSame($says, $openai->check()['says']);
        }

        $this->assertSame('No API key saved for Mistral yet.', (new Connection('mistral'))->check()['says']);
        $this->configure(['providers' => ['deepseek' => ['transport' => DeadTransport::class]]]);
        $this->setting('llm_deepseek_api_key', 'sk-d');
        $this->assertStringStartsWith('Nothing answered at', (new Connection('deepseek'))->check()['says']);
    }

    /**
     * A transport that is not one is replaced by the default rather than failing.
     */
    public function testAnInvalidTransportFallsBack(): void
    {
        // Arrange
        $this->configure(['providers' => ['openai' => ['transport' => \stdClass::class]]]);

        // Assert
        $this->assertInstanceOf(\Pramnos\Llm\Transport\OpenAiCompatible::class, (new Connection('openai'))->transport());
    }
}
