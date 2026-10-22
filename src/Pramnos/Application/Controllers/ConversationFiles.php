<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Conversations\Access;
use Pramnos\Conversations\Conversations as Store;
use Pramnos\Conversations\Files;
use Pramnos\Conversations\People;

/**
 * `/admin/ConversationFiles` — the space the conversations' files take: in all, per conversation
 * and month, the largest and the oldest still kept; the largest file allowed; and removing files to
 * free space — old ones in bulk, with a report of how many and how much, or one at a time. A removed
 * file keeps its place in its message, which says when it went.
 *
 * Behind `admin.chat.files`, a superuser's by default — removing other people's files is not
 * everybody's.
 *
 * Actions:
 *   - display()    — the space, the limit, the lists
 *   - limit()      — POST: the largest file, in MB
 *   - prune()      — POST: remove the files older than `days`, of one `conversation` or all
 *   - remove($id)  — POST: remove one file
 */
class ConversationFiles extends Controller
{
    /** The administration ability that opens this screen. */
    protected string $adminAbility = Access::FILES;

    /** Registers the actions: all behind sign-in, the writes POSTs with the session's token. */
    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'limit', 'prune', 'remove']);
        $this->addWriteAction(['limit', 'prune', 'remove']);
        parent::__construct($application);
    }

    /** A superuser's by default, whatever the administration's floor. */
    protected function adminFloor(): int
    {
        return \Pramnos\Auth\AdminAccess::superuserUsertype();
    }

    /** The space, the limit, and the files by conversation and month, the largest, the oldest. */
    public function display(): mixed
    {
        \Pramnos\Framework\Factory::getDocument()->title = t('Conversation files');
        $usage  = Files::usage();
        $ids    = array_values(array_unique(array_merge(array_column($usage['byConversation'], 'conversationId'),
            array_column($usage['largest'], 'conversationId'), array_column($usage['oldest'], 'conversationId'))));
        $people = People::names(array_merge(array_column($usage['largest'], 'uploadedBy'), array_column($usage['oldest'], 'uploadedBy')));

        $view                = $this->getView('conversationfiles');
        $view->usage         = $usage;
        $view->conversations = Store::names($ids);
        $view->people        = $people;
        $view->limit         = Files::maxMegabytes();

        return $view->display();
    }

    /** The largest file a conversation takes, in MB: kept as the setting an administrator changes. */
    public function limit(): mixed
    {
        $mb = (int) \Pramnos\Http\Request::staticGet('mb', 0, 'post');
        if ($mb < 1 || $mb > 1024) {
            $this->addError(t('Between 1 and 1024 MB.'));
        } else {
            \Pramnos\Application\Settings::setSetting(Files::SETTING, $mb);
            $this->addMessage(t('A file is now at most %s MB.', (string) $mb));
        }
        $this->redirect(adminUrl('ConversationFiles'));

        return null;
    }

    /** Remove the files older than so many days, of one conversation or all, and say what went. */
    public function prune(): mixed
    {
        $days         = (int) \Pramnos\Http\Request::staticGet('days', 0, 'post');
        $conversation = (int) \Pramnos\Http\Request::staticGet('conversation', 0, 'post');
        if ($days < 0) {
            $this->addError(t('How many days back, from 0 for every one.'));
        } else {
            $report = Files::prune($days, $conversation > 0 ? $conversation : null);
            $this->addMessage(t('%s files removed, %s freed.', (string) $report['files'], self::bytes($report['bytes'])));
        }
        $this->redirect(adminUrl('ConversationFiles'));

        return null;
    }

    /** Remove one file. */
    public function remove(): mixed
    {
        $freed = Files::remove((int) \Pramnos\Http\Request::staticGetOption());
        $freed > 0 ? $this->addMessage(t('The file was removed, %s freed.', self::bytes($freed))) : $this->addError(t('That file is not here.'));
        $this->redirect(adminUrl('ConversationFiles'));

        return null;
    }

    /** A size as people read it. */
    public static function bytes(int $bytes): string
    {
        foreach (['GB' => 1073741824, 'MB' => 1048576, 'KB' => 1024] as $unit => $size) {
            if ($bytes >= $size) {
                return number_format($bytes / $size, 1, ',', '.') . ' ' . $unit;
            }
        }

        return $bytes . ' B';
    }
}
