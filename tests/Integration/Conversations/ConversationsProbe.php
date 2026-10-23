<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Conversations;

use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Migrations\Auth\CreateUsersTable;

/**
 * A throwaway database for the conversation tests, on MySQL (with and without a table prefix) and
 * on PostgreSQL with TimescaleDB — where the messages are a hypertable: five accounts — eleni and
 * maria, administrators; nikos, sales; tasos, a translator; and root, who reads everything.
 */
trait ConversationsProbe
{
    use \Pramnos\Tests\Support\ReusesProbeDatabase;

    /** The throwaway database's name. */
    private static string $probe = 'pramnos_conversations_probe';

    private ?Database $admin = null;

    private ?Database $db = null;

    private ?Database $previous = null;

    /** @var array<string, int> Ids by username */
    private array $ids = [];

    /** Whether this test built a prefixed database, which the next test must not reuse. */
    private bool $prefixed = false;

    /** @var list<array{0: string, 1: object}> Events dispatched during the test */
    private array $dispatched = [];

    /**
     * The backends, and MySQL with a table prefix.
     *
     * @return array<string, array{string, string, int, string}>
     */
    public static function databases(): array
    {
        return [
            'mysql'           => ['mysql', 'db', 3306, 'root'],
            'mysql, prefixed' => ['mysql+pf_', 'db', 3306, 'root'],
            'postgresql'      => ['postgresql', 'timescaledb', 5432, 'postgres'],
        ];
    }

    /** Open the throwaway database on this backend, make it the application's, and seed it. */
    private function boot(string $type, string $host, int $port, string $user): void
    {
        require_once ROOT . '/database/migrations/framework/conversations/2026_10_22_000001_create_conversations_tables.php';
        require_once ROOT . '/database/migrations/framework/changelog/2026_08_25_000001_create_changelog_tables.php';
        require_once ROOT . '/database/migrations/framework/authserver/2020_01_01_000024_create_authserver_audit_log_table.php';
        require_once ROOT . '/database/migrations/framework/changelog/2026_10_04_000001_add_scope_to_changelog_events.php';
        require_once ROOT . '/database/migrations/framework/changelog/2026_10_22_000001_add_event_index_to_changelog_events.php';
        require_once ROOT . '/database/migrations/framework/auth/2020_01_01_000011_create_userdetails_table.php';
        require_once ROOT . '/database/migrations/framework/conversations/2026_10_22_000003_add_starred_to_conversation_members.php';
        require_once ROOT . '/database/migrations/framework/conversations/2026_10_22_000004_create_conversation_saved_table.php';
        require_once ROOT . '/database/migrations/framework/conversations/2026_10_22_000005_make_conversation_reactions_emoji_binary.php';
        require_once ROOT . '/database/migrations/framework/moderation/2026_10_23_000001_create_moderation_tables.php';
        require_once ROOT . '/database/migrations/framework/conversations/2026_10_23_000001_add_broadcast_to_conversation_messages.php';
        require_once ROOT . '/database/migrations/framework/conversations/2026_10_23_000002_add_former_members_to_conversation_members.php';
        require_once ROOT . '/database/migrations/framework/conversations/2026_10_23_000003_index_conversation_messages_by_time.php';
        require_once ROOT . '/database/migrations/framework/conversations/2026_10_23_000004_add_unfurls_to_conversation_messages.php';
        require_once ROOT . '/database/migrations/framework/conversations/2026_10_23_000005_create_link_previews_table.php';
        require_once ROOT . '/database/migrations/framework/llm/2026_10_13_000003_create_chat_conversations_table.php';
        require_once ROOT . '/database/migrations/framework/llm/2026_10_13_000004_create_chat_messages_table.php';
        require_once ROOT . '/database/migrations/framework/llm/2026_10_13_000009_add_tags_to_chat_conversations.php';
        require_once ROOT . '/database/migrations/framework/llm/2026_10_13_000010_add_extra_to_chat_messages.php';
        require_once ROOT . '/database/migrations/framework/llm/2026_10_13_000006_create_chat_asks_table.php';
        require_once ROOT . '/database/migrations/framework/llm/2026_10_13_000011_add_space_to_chat_tables.php';
        require_once ROOT . '/database/migrations/framework/llm/2026_10_13_000012_make_organization_id_nullable_on_chat_tables.php';
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        [$type, $prefix] = array_pad(explode('+', $type, 2), 2, '');
        $make = static function (string $name) use ($type, $host, $port, $user, $prefix): Database {
            $db           = new Database();
            $db->type     = $type;
            $db->server   = $host;
            $db->port     = $port;
            $db->user     = $user;
            $db->password = 'secret';
            $db->database = $name;
            $db->prefix   = $prefix;

            return $db;
        };
        $this->admin = $make(TEST_DATABASE);
        try {
            if (!$this->admin->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }
        if ($prefix !== '') {
            $this->probeChanged($type);
        }
        $this->prefixed = $prefix !== '';
        $this->db = $this->openProbe($this->admin, self::$probe, $make, [
            CreateUsersTable::class,
            \Pramnos\Framework\Migrations\Auth\CreateUserdetailsTable::class,
            \Pramnos\Framework\Migrations\Conversations\CreateConversationsTables::class,
            \Pramnos\Framework\Migrations\Conversations\AddStarredToConversationMembers::class,
            \Pramnos\Framework\Migrations\Conversations\CreateConversationSavedTable::class,
            \Pramnos\Framework\Migrations\Conversations\MakeConversationReactionsEmojiBinary::class,
            \Pramnos\Framework\Migrations\Conversations\AddBroadcastToConversationMessages::class,
            \Pramnos\Framework\Migrations\Conversations\AddFormerMembersToConversationMembers::class,
            \Pramnos\Framework\Migrations\Conversations\IndexConversationMessagesByTime::class,
            \Pramnos\Framework\Migrations\Conversations\AddUnfurlsToConversationMessages::class,
            \Pramnos\Framework\Migrations\Conversations\CreateLinkPreviewsTable::class,
            // The changelog: what a message said before an edit, and what moderation did.
            \Pramnos\Framework\Migrations\Changelog\CreateChangelogTables::class,
            \Pramnos\Framework\Migrations\Changelog\AddScopeToChangelogEvents::class,
            \Pramnos\Framework\Migrations\Changelog\AddEventIndexToChangelogEvents::class,
            // Moderation: who may write where, in the conversations and anywhere else.
            // …and what was done about whom: the audit log.
            \Pramnos\Framework\Migrations\AuthServer\CreateAuthserverAuditLogTable::class,
            \Pramnos\Framework\Migrations\Moderation\CreateModerationTables::class,
            // The analyst's conversations and asks: where a conversation with Ask finds the one it
            // goes on, and what a worker answers.
            \Pramnos\Framework\Migrations\Llm\CreateChatConversationsTable::class,
            \Pramnos\Framework\Migrations\Llm\CreateChatMessagesTable::class,
            \Pramnos\Framework\Migrations\Llm\AddTagsToChatConversations::class,
            \Pramnos\Framework\Migrations\Llm\AddExtraToChatMessages::class,
            \Pramnos\Framework\Migrations\Llm\CreateChatAsksTable::class,
            \Pramnos\Framework\Migrations\Llm\AddSpaceToChatTables::class,
            \Pramnos\Framework\Migrations\Llm\MakeOrganizationIdNullableOnChatTables::class,
        ]);
        $this->previous = Factory::getDatabase();
        $singleton      = &Factory::getDatabase();
        $singleton      = $this->db;
        \Pramnos\Event\Event::forget();
        // The changelog written at once, and listening to the change feed, as with the feature on.
        \Pramnos\Database\WriteSpool::setDriver(\Pramnos\Database\WriteSpool::DRIVER_SYNC);
        \Pramnos\Changelog\ChangelogWriter::listen();
        \Pramnos\Auth\AuditLog::resetTableCache();
        foreach (['conversation.created', 'conversation.message.posted', 'conversation.mention.received'] as $event) {
            \Pramnos\Event\Event::listen($event, function (object $e) use ($event): void {
                $this->dispatched[] = [$event, $e];
            });
        }
        $this->seed();
    }

    /** Give the application its database back. */
    protected function tearDown(): void
    {
        if ($this->prefixed && $this->db !== null) {
            $this->probeChanged($this->db->type);
        }
        \Pramnos\Event\Event::forget();
        \Pramnos\Database\WriteSpool::setDriver(null);
        unset(Application::getInstance()->applicationInfo['conversations']);
        if ($this->previous !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previous;
        }
        $this->db?->close();
        $this->admin?->close();
    }

    /** The five accounts. */
    private function seed(): void
    {
        foreach (['eleni' => [98, 'Ελένη', 'Σ.'], 'maria' => [97, 'Μαρία', 'Κ.'], 'nikos' => [30, 'Νίκος', 'Π.'], 'tasos' => [11, 'Τάσος', ''], 'root' => [99, '', '']] as $name => [$type, $first, $last]) {
            $this->db->queryBuilder()->table('users')->insert(['username' => $name, 'email' => $name . '@example.com', 'firstname' => $first,
                'lastname' => $last, 'usertype' => $type, 'active' => 1, 'regdate' => time()]);
            $this->ids[$name] = (int) $this->db->queryBuilder()->table('users')->where('username', $name)->first()->fields['userid'];
        }
    }

    /** An account as the conversations see it. */
    private function user(string $name): object
    {
        $row = $this->db->queryBuilder()->table('users')->where('userid', $this->ids[$name])->first()->fields;

        return (object) ['userid' => (int) $row['userid'], 'usertype' => (int) $row['usertype'], 'active' => (int) $row['active']];
    }
}
