<?php

namespace Pramnos\Framework\Migrations\Conversations;

use Pramnos\Database\DatabaseCapabilities;
use Pramnos\Database\Migration;

/**
 * Creates the conversations: who talks where, what they wrote, and what they did with it.
 *
 * - `conversations` — a channel (open or closed), a direct conversation of two, a group of a few,
 *   a channel the system writes to; later support and the site's own.
 * - `conversation_members` — a participant (an account or a visitor) in one, with what they have
 *   read, whether they muted it and what they are told about it.
 * - `conversation_messages` — markdown-lite text, a thread's root and its replies counted, edited
 *   and deleted marks, and where an imported message came from (`source`, `externalid`). On
 *   TimescaleDB a hypertable on `created_at`, its old chunks compressed: messages are kept for good
 *   ({@see \Pramnos\Database\HypertableRegistry}).
 * - `conversation_message_edits`, `conversation_reactions`, `conversation_mentions`,
 *   `conversation_pins`, `conversation_files` — the rest. A file is a file on disk under its own
 *   tree; the row keeps its relative path.
 *
 * Messages are searched with a FULLTEXT index on MySQL and MariaDB and a `simple` tsvector GIN
 * index on PostgreSQL, created here as DDL. Every table holds what people wrote, and is declared
 * personal data. Times are Unix seconds, except a message's `created_at`, the hypertable's.
 */
class CreateConversationsTables extends Migration
{
    public string $feature     = 'conversations';
    public string $scope       = 'framework';
    public int    $priority    = 10;
    public $description = 'Creates the conversations: members, messages, edits, reactions, mentions, pins and files';

    /** Create the tables. */
    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('conversations')) {
            $schema->createTable('conversations', function ($table) {
                $table->comment('A channel, a direct or group conversation, or one the system writes to');
                $table->bigIncrements('conversationid')->comment('Auto-increment identifier');
                $table->string('kind', 16)->comment('channel | private | dm | group | system');
                $table->string('slug', 80)->nullable()->comment('A channel\'s name for #mentions and links; null for a dm or group');
                $table->string('name', 150)->nullable()->comment('Its name as shown');
                $table->text('topic')->nullable()->comment('What it is for');
                $table->integer('retentiondays')->nullable()->comment('Days its messages are kept; null keeps them for good');
                $table->bigInteger('createdby')->nullable()->comment('The account that made it');
                $table->unsignedInteger('createdat')->comment('When it was made');
                $table->unsignedInteger('archivedat')->nullable()->comment('When it was archived');
                $table->bigInteger('lastmessageid')->nullable()->comment('Its newest message');
                $table->unsignedInteger('lastmessageat')->nullable()->comment('When that was written');
                $table->unique(['slug'], 'uq_conversations_slug');
                $table->index(['kind', 'archivedat'], 'idx_conversations_kind');
            });
        }

        if (!$schema->hasTable('conversation_members')) {
            $schema->createTable('conversation_members', function ($table) {
                $table->comment('A participant in a conversation, with what they have read and are told');
                $table->bigIncrements('id')->comment('Auto-increment identifier');
                $table->bigInteger('conversationid')->comment('conversations.conversationid');
                $table->string('participanttype', 10)->default('user')->comment('user | visitor');
                $table->bigInteger('participantid')->comment('users.userid, or the visitor\'s id');
                $table->string('role', 10)->default('member')->comment('owner | member');
                $table->bigInteger('lastreadid')->default(0)->comment('The newest message they have read');
                $table->tinyInteger('muted')->default(0)->comment('Whether they muted it');
                $table->string('notify', 10)->default('all')->comment('all | mentions | none');
                $table->unsignedInteger('joinedat')->comment('When they joined');
                $table->unsignedInteger('leftat')->nullable()->comment('When they left; null while they are in it');
                $table->unique(['conversationid', 'participanttype', 'participantid'], 'uq_conversation_members');
                $table->index(['participanttype', 'participantid', 'leftat'], 'idx_conversation_members_person');
            });
        }

        if (!$schema->hasTable('conversation_messages')) {
            $schema->createTable('conversation_messages', function ($table) {
                $table->comment('What was written in a conversation; kept for good');
                $table->bigIncrements('messageid')->comment('Auto-increment identifier (with created_at, the key)');
                $table->bigInteger('conversationid')->comment('conversations.conversationid');
                $table->string('authortype', 10)->default('user')->comment('user | visitor | system');
                $table->bigInteger('authorid')->nullable()->comment('Who wrote it; null for the system');
                $table->text('body')->comment('The text, markdown-lite');
                $table->text('card')->nullable()->comment('A system message\'s card, as JSON');
                $table->bigInteger('threadrootid')->nullable()->comment('The message it answers in a thread');
                $table->integer('replycount')->default(0)->comment('A root\'s replies');
                $table->unsignedInteger('lastreplyat')->nullable()->comment('A root\'s newest reply');
                $table->unsignedInteger('editedat')->nullable()->comment('When it was last edited');
                $table->unsignedInteger('deletedat')->nullable()->comment('When it was deleted; its text is gone');
                $table->string('source', 20)->nullable()->comment('Where an imported message came from: the application names it');
                $table->string('externalid', 100)->nullable()->comment('Its id there, so an import runs once');
                $table->text('refs')->nullable()->comment('Objects it refers to, as JSON: user:48211, jira:NAN-6212');
                $table->timestampTz('created_at')->useCurrent()->comment('When it was written; the hypertable\'s time');
                $table->primary(['messageid', 'created_at']);
                $table->index(['conversationid', 'messageid'], 'idx_conversation_messages_conv');
                $table->index(['threadrootid'], 'idx_conversation_messages_thread');
                $table->index(['source', 'externalid'], 'idx_conversation_messages_external');
            });
            $this->searchIndex();
            if ($schema->getCapabilities()->supports(DatabaseCapabilities::TIMESCALEDB)) {
                \Pramnos\Database\HypertableRegistry::apply($schema, 'conversation_messages');
            }
        }

        if (!$schema->hasTable('conversation_message_edits')) {
            $schema->createTable('conversation_message_edits', function ($table) {
                $table->comment('What a message said before each edit');
                $table->bigIncrements('id')->comment('Auto-increment identifier');
                $table->bigInteger('messageid')->comment('conversation_messages.messageid');
                $table->text('body')->comment('The text before this edit');
                $table->bigInteger('editedby')->nullable()->comment('Who edited it');
                $table->unsignedInteger('editedat')->comment('When');
                $table->index(['messageid'], 'idx_conversation_message_edits');
            });
        }

        if (!$schema->hasTable('conversation_reactions')) {
            $schema->createTable('conversation_reactions', function ($table) {
                $table->comment('An emoji a participant put on a message');
                $table->bigIncrements('id')->comment('Auto-increment identifier');
                $table->bigInteger('messageid')->comment('conversation_messages.messageid');
                $table->string('participanttype', 10)->default('user')->comment('user | visitor');
                $table->bigInteger('participantid')->comment('Who');
                $table->string('emoji', 64)->collation('utf8mb4_bin')->comment('The emoji, or its :shortcode: — binary, so each emoji is its own value');
                $table->unsignedInteger('createdat')->comment('When');
                $table->unique(['messageid', 'participanttype', 'participantid', 'emoji'], 'uq_conversation_reactions');
            });
        }

        if (!$schema->hasTable('conversation_mentions')) {
            $schema->createTable('conversation_mentions', function ($table) {
                $table->comment('Who a message mentions: a person, everybody, or whoever is online');
                $table->bigIncrements('id')->comment('Auto-increment identifier');
                $table->bigInteger('messageid')->comment('conversation_messages.messageid');
                $table->bigInteger('conversationid')->comment('Its conversation');
                $table->string('kind', 10)->comment('person | all | online');
                $table->bigInteger('participantid')->nullable()->comment('The person, for kind person');
                $table->unsignedInteger('createdat')->comment('When');
                $table->index(['participantid', 'createdat'], 'idx_conversation_mentions_person');
            });
        }

        if (!$schema->hasTable('conversation_pins')) {
            $schema->createTable('conversation_pins', function ($table) {
                $table->comment('A message pinned to its conversation');
                $table->bigInteger('conversationid')->comment('conversations.conversationid');
                $table->bigInteger('messageid')->comment('conversation_messages.messageid');
                $table->bigInteger('pinnedby')->nullable()->comment('Who pinned it');
                $table->unsignedInteger('pinnedat')->comment('When');
                $table->primary(['conversationid', 'messageid']);
            });
        }

        if (!$schema->hasTable('conversation_files')) {
            $schema->createTable('conversation_files', function ($table) {
                $table->comment('A file sent in a conversation: a file on disk, its relative path here');
                $table->bigIncrements('fileid')->comment('Auto-increment identifier');
                $table->bigInteger('conversationid')->comment('conversations.conversationid');
                $table->bigInteger('messageid')->nullable()->comment('The message it came with');
                $table->bigInteger('uploadedby')->nullable()->comment('Who sent it');
                $table->string('name', 255)->comment('Its name as sent');
                $table->string('path', 255)->comment('Where it is, under the files directory');
                $table->string('thumbpath', 255)->nullable()->comment('Its thumbnail, for an image');
                $table->string('mime', 100)->comment('Its type');
                $table->bigInteger('size')->comment('Bytes');
                $table->unsignedInteger('createdat')->comment('When it was sent');
                $table->unsignedInteger('removedat')->nullable()->comment('When it was removed to free space');
                $table->index(['conversationid', 'createdat'], 'idx_conversation_files_conv');
                $table->index(['messageid'], 'idx_conversation_files_message');
            });
        }
    }

    /** Drop them. */
    public function down(): void
    {
        $schema = $this->application->database->schema();
        foreach (['conversation_files', 'conversation_pins', 'conversation_mentions', 'conversation_reactions',
            'conversation_message_edits', 'conversation_messages', 'conversation_members', 'conversations'] as $table) {
            $schema->dropTableIfExists($table);
        }
    }

    /**
     * The index messages are searched with: FULLTEXT on MySQL and MariaDB, a `simple` tsvector GIN
     * on PostgreSQL — `simple`, because a stemmer for one language mangles the other, and the
     * team writes in two. DDL, so written out: the builder has no word for either.
     */
    private function searchIndex(): void
    {
        $db    = $this->application->database;
        $table = $db->prefix . 'conversation_messages';
        if ($db->schema()->getCapabilities()->isPostgreSQL()) {
            $db->query('CREATE INDEX IF NOT EXISTS idx_conversation_messages_search ON ' . $table . " USING GIN (to_tsvector('simple', body))");

            return;
        }
        $db->query('ALTER TABLE `' . $table . '` ADD FULLTEXT INDEX idx_conversation_messages_search (body)');
    }
}
