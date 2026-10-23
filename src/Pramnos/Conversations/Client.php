<?php

declare(strict_types=1);

namespace Pramnos\Conversations;

/**
 * What `pf-conversations.js` is started with — on the conversations page and in the dock over
 * other pages: who is reading, where the JSON is, the channels, the limits, and every word it
 * shows, translated for the reader.
 */
final class Client
{
    /**
     * The conversations as an app of their own, where `conversations.app` is set — its name, colours
     * and icons, for the manifest — or null.
     *
     * ```php
     * 'conversations' => ['app' => ['name' => 'Team Chat', 'icons' => [
     *     ['src' => '/assets/chat-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
     *     ['src' => '/assets/chat-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
     * ]]],
     * ```
     *
     * @return array{name: string, short_name: string, theme_color: string, background_color: string, icons: list<array<string, string>>}|null
     */
    public static function app(): ?array
    {
        $info = \Pramnos\Application\Application::currentInstance()?->applicationInfo['conversations']['app'] ?? null;
        if (!is_array($info)) {
            return null;
        }
        $name = (string) ($info['name'] ?? t('Conversations'));

        return ['name' => $name, 'short_name' => (string) ($info['short_name'] ?? $name), 'theme_color' => (string) ($info['theme_color'] ?? '#1f2937'),
            'background_color' => (string) ($info['background_color'] ?? '#ffffff'), 'icons' => array_values((array) ($info['icons'] ?? []))];
    }

    /**
     * What `pf-conversations.js` starts from: who is looking, where the JSON is, the channels, the
     * limits, and every word it shows, in the reader's language.
     *
     * @param string $mode `page`, or `dock` for the panel over other pages
     * @return array<string, mixed>
     */
    public static function props(object $me, string $mode = 'page'): array
    {
        $app = \Pramnos\Application\Application::currentInstance();

        return [
            'mode'      => $mode,
            'base'      => adminUrl('Conversations/'),
            'page'      => adminUrl('Conversations'),
            'me'        => ['id' => (int) $me->userid, 'name' => People::names([(int) $me->userid])[(int) $me->userid] ?? ''],
            'userChannel' => \Pramnos\Broadcasting\RealtimeConfig::userChannel((int) $me->userid,
                is_object($app) && is_array($app->applicationInfo['broadcasting'] ?? null) ? $app->applicationInfo['broadcasting'] : []),
            'maxFileMb' => Files::maxMegabytes(),
            'emoji'     => Markdown::EMOJI,
            'refTypes'  => References::types(),
            'commands'  => Commands::all(),
            'ask'       => Ask::canAsk($me),
            // Where this browser goes when this server does not answer, and its emergency channel.
            'beacon'    => Beacon\Config::ready()
                ? ['urls' => array_values(array_map('strval', (array) Beacon\Config::value('urls', []))), 'channel' => Beacon\Config::channel()] : null,
            'files'     => Access::managesFiles($me) ? adminUrl('ConversationFiles') : null,
            'links'     => self::links($me),
            'strings'   => self::strings(),
        ];
    }

    /**
     * What the basic page starts from (`pf-conversations-basic.js`): where the JSON is, who is
     * reading, their realtime channel, and its few words in the reader's language.
     *
     * @return array{base: string, me: array{id: int, name: string}, userChannel: string, strings: array<string, string>}
     */
    public static function basic(object $me): array
    {
        $app = \Pramnos\Application\Application::currentInstance();

        return [
            'base'        => adminUrl('Conversations/'),
            'me'          => ['id' => (int) $me->userid, 'name' => People::names([(int) $me->userid])[(int) $me->userid] ?? ''],
            'userChannel' => \Pramnos\Broadcasting\RealtimeConfig::userChannel((int) $me->userid,
                is_object($app) && is_array($app->applicationInfo['broadcasting'] ?? null) ? $app->applicationInfo['broadcasting'] : []),
            'strings'     => array_map(static fn (string $s): string => t($s), array_combine(self::BASIC_WORDS, self::BASIC_WORDS)),
        ];
    }

    /** The words the basic page shows, beside those its view writes. */
    private const BASIC_WORDS = ['No conversations yet.', 'This message was deleted.', 'System message', 'Choose a conversation'];

    /**
     * The application's own links at the foot of the conversations' list, on the page and in the
     * dock — `conversations.links`, each `label` (already in the reader's language), `url` and
     * `icon`, shown to whoever passes `ability` (at `usertype`, or the conversations' floor, in
     * usertype mode) or `visible`, a callable given the reader:
     *
     * ```php
     * 'conversations' => ['links' => [
     *     ['label' => t('Old messages'), 'url' => adminUrl('Archive'), 'icon' => '⇄', 'ability' => 'admin.archive'],
     * ]],
     * ```
     *
     * @return list<array{label: string, url: string, icon: string}>
     */
    private static function links(object $me): array
    {
        $app   = \Pramnos\Application\Application::currentInstance();
        $links = [];
        foreach ((array) (is_object($app) ? ($app->applicationInfo['conversations']['links'] ?? []) : []) as $link) {
            if (!is_array($link) || trim((string) ($link['label'] ?? '')) === '' || trim((string) ($link['url'] ?? '')) === '') {
                continue;
            }
            if (isset($link['ability']) && !\Pramnos\Auth\AdminAccess::allows($me, (string) $link['ability'], (int) ($link['usertype'] ?? Access::floor()))) {
                continue;
            }
            if (isset($link['visible']) && is_callable($link['visible']) && !($link['visible'])($me)) {
                continue;
            }
            $links[] = ['label' => trim((string) $link['label']), 'url' => (string) $link['url'], 'icon' => (string) ($link['icon'] ?? '')];
        }

        return $links;
    }

    /**
     * Every word `pf-conversations.js` shows, in the reader's language, by its English. A word the
     * components use and this does not list is shown in English — tests/js/conversations-build.test.js
     * fails when that happens.
     *
     * @return array<string, string>
     */
    public static function strings(): array
    {
        return [
            'Open in Ask' => t('Open in Ask'),
            'everybody here' => t('everybody here'),
            'everybody here who is online' => t('everybody here who is online'),
            'the analyst answers in the thread' => t('the analyst answers in the thread'),
            'A file is at most %s MB.' => t('A file is at most %s MB.'),
            'uploading…' => t('uploading…'),
            'Remove' => t('Remove'),
            'Attach a file' => t('Attach a file'),
            'Insert an emoji' => t('Insert an emoji'),
            'Send' => t('Send'),
            'Enter to send · Shift+Enter for a new line · @ to mention' => t('Enter to send · Shift+Enter for a new line · @ to mention'),
            'Started by %s on %s.' => t('Started by %s on %s.'),
            'Started on %s.' => t('Started on %s.'),
            'Nothing here yet.' => t('Nothing here yet.'),
            'Write the first message below.' => t('Write the first message below.'),
            'New messages' => t('New messages'),
            'Only you can see this.' => t('Only you can see this.'),
            'Close' => t('Close'),
            'Ask is thinking…' => t('Ask is thinking…'),
            '%s is typing…' => t('%s is typing…'),
            'Several people are typing…' => t('Several people are typing…'),
            'Has not signed in since %s — they will probably not see this.' => t('Has not signed in since %s — they will probably not see this.'),
            'Has never signed in — they will probably not see this.' => t('Has never signed in — they will probably not see this.'),
            'Message #%s' => t('Message #%s'),
            'Message %s' => t('Message %s'),
            'You are reading #%s.' => t('You are reading #%s.'),
            'Join to write' => t('Join to write'),
            'Thread' => t('Thread'),
            'Reply…' => t('Reply…'),
            '%s new message' => t('%s new message'),
            '%s new messages' => t('%s new messages'),
            '%s reply' => t('%s reply'),
            '%s replies' => t('%s replies'),
            'Put away' => t('Put away'),
            'Conversations' => t('Conversations'),
            'New conversation' => t('New conversation'),
            'Find a conversation' => t('Find a conversation'),
            'Muted' => t('Muted'),
            'Nothing found.' => t('Nothing found.'),
            'Start a conversation, or join a channel.' => t('Start a conversation, or join a channel.'),
            'Browse channels' => t('Browse channels'),
            'Files and space' => t('Files and space'),
            'Open the whole page' => t('Open the whole page'),
            'Faces above the button' => t('Faces above the button'),
            'Open' => t('Open'),
            'Minimise' => t('Minimise'),
            'Emoji: reactions' => t('Emoji: reactions'),
            'Emoji: faces' => t('Emoji: faces'),
            'Emoji: work' => t('Emoji: work'),
            'System message' => t('System message'),
            '(edited)' => t('(edited)'),
            'You' => t('You'),
            'Delete this message? It cannot be brought back.' => t('Delete this message? It cannot be brought back.'),
            'Former member' => t('Former member'),
            'Pinned message' => t('Pinned message'),
            'Replied to a thread:' => t('Replied to a thread:'),
            'This message was deleted.' => t('This message was deleted.'),
            'Enter to save · Esc to cancel' => t('Enter to save · Esc to cancel'),
            'Cancel' => t('Cancel'),
            'Save' => t('Save'),
            'removed on %s' => t('removed on %s'),
            'Add a reaction' => t('Add a reaction'),
            'last at %s' => t('last at %s'),
            'Message actions' => t('Message actions'),
            'Reply in thread' => t('Reply in thread'),
            'Remove from saved' => t('Remove from saved'),
            'Save for later' => t('Save for later'),
            'Unpin message' => t('Unpin message'),
            'Pin message' => t('Pin message'),
            'Edit message' => t('Edit message'),
            'Delete message' => t('Delete message'),
            'Message people' => t('Message people'),
            'New channel' => t('New channel'),
            'Find people by name' => t('Find people by name'),
            'Bot · every message a question to the analyst' => t('Bot · every message a question to the analyst'),
            'Inactive: last signed in %s' => t('Inactive: last signed in %s'),
            'Inactive: never signed in' => t('Inactive: never signed in'),
            'Nobody by that name.' => t('Nobody by that name.'),
            'Show inactive people too' => t('Show inactive people too'),
            'A group conversation of %s people and you.' => t('A group conversation of %s people and you.'),
            'One person: a direct conversation; more: a group.' => t('One person: a direct conversation; more: a group.'),
            'Start conversation' => t('Start conversation'),
            'Channel name' => t('Channel name'),
            'e.g. sales' => t('e.g. sales'),
            'What it is for' => t('What it is for'),
            'Closed: only the people its members add' => t('Closed: only the people its members add'),
            'Create channel' => t('Create channel'),
            '%s members' => t('%s members'),
            'Open channel' => t('Open channel'),
            'Join channel' => t('Join channel'),
            'There are no open channels yet.' => t('There are no open channels yet.'),
            'Today' => t('Today'),
            'Yesterday' => t('Yesterday'),
        ];
    }
}
