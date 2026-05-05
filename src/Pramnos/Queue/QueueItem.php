<?php

declare(strict_types=1);

namespace Pramnos\Queue;

/**
 * ORM model for the queueitems table.
 *
 * Each row represents one background task. Extend this class and override
 * getItemShowUrl(), getItemEditUrl(), getItemDeleteUrl() to point the admin
 * datatable actions at your application's controller URLs.
 *
 * Status lifecycle:
 *   pending → processing → completed | failed | warning
 *
 * @package     PramnosFramework
 * @subpackage  Queue
 *
 * @property int         $taskid
 * @property string      $type
 * @property string      $payload        JSON-encoded task payload
 * @property string      $status         pending|processing|completed|failed|warning
 * @property int         $priority       Lower = higher priority
 * @property int         $attempts
 * @property int         $maxattempts
 * @property string      $createdat
 * @property string|null $updatedat
 * @property string|null $startedat
 * @property string|null $completedat
 * @property string|null $error
 * @property string|null $lockedby       hostname:workerid
 * @property string|null $lockexpires
 * @property string|null $task_hash      SHA-256 for deduplication
 * @property float|null  $execution_time Wall-clock seconds
 * @property string|null $success_message
 */
class QueueItem extends \Pramnos\Application\Model
{
    /** @var int */
    public $taskid;
    /** @var string  Task type name — maps to a registered TaskInterface handler */
    public $type;
    /** @var string  JSON-encoded payload */
    public $payload;
    /** @var string  pending|processing|completed|failed|warning */
    public $status;
    /** @var int  Lower number = higher priority */
    public $priority;
    /** @var int  Execution attempts so far */
    public $attempts;
    /** @var int  Maximum retry attempts */
    public $maxattempts;
    /** @var string */
    public $createdat;
    /** @var string|null */
    public $updatedat;
    /** @var string|null  When processing began */
    public $startedat;
    /** @var string|null  When processing finished */
    public $completedat;
    /** @var string|null  Last error message */
    public $error;
    /** @var string|null  Worker ID that holds this item */
    public $lockedby;
    /** @var string|null  When the processing lock expires */
    public $lockexpires;
    /** @var string|null  SHA-256 hash for optional uniqueness */
    public $task_hash;
    /** @var float|null  Execution time in seconds */
    public $execution_time;
    /** @var string|null  Human-readable success/warning message */
    public $success_message;

    /** @var string */
    protected $_primaryKey = 'taskid';

    /** @var string  Framework table — no prefix needed */
    protected $_dbtable = 'queueitems';

    /** @var bool  Never cache queue items */
    protected $useCacheInLists = false;

    // ── CRUD ─────────────────────────────────────────────────────────────────

    /**
     * Load a queue item by its primary key.
     *
     * @param  int|string $taskid
     * @param  string|null $key
     * @param  bool $debug
     * @return static
     */
    public function load($taskid, $key = null, $debug = false)
    {
        return parent::_load($taskid, null, $key, $debug);
    }

    /**
     * Persist the queue item (insert or update).
     *
     * @param  bool $autoGetValues
     * @param  bool $debug
     * @return static
     */
    public function save($autoGetValues = false, $debug = false)
    {
        return parent::_save(null, null, $autoGetValues, $debug);
    }

    /**
     * Delete a queue item by primary key.
     *
     * @param  int|string $taskid
     * @return static
     */
    public function delete($taskid)
    {
        return parent::_delete($taskid, null, null);
    }

    /**
     * Return all properties as an associative array.
     *
     * @return array<string, mixed>
     */
    public function getData()
    {
        return parent::getData();
    }

    /**
     * Return a list of queue items matching the given filter.
     *
     * @param  string|null $filter  SQL WHERE clause (without the WHERE keyword)
     * @param  string|null $order   SQL ORDER / LIMIT clause
     * @param  bool $debug
     * @return static[]
     */
    public function getList($filter = null, $order = null, $debug = false)
    {
        return parent::_getList($filter, $order, null, null, $debug);
    }

    // ── Admin datatable ───────────────────────────────────────────────────────

    /**
     * Return a JSON-encoded DataTable-compatible list for admin UIs.
     *
     * Override getItemShowUrl(), getItemEditUrl(), getItemDeleteUrl() in a
     * subclass to point the action links at the correct controller routes.
     *
     * @return string JSON
     */
    public function getJsonList(): string
    {
        $fields = [
            'taskid', 'type', 'status', 'priority',
            'attempts', 'createdat', 'startedat', 'completedat',
            'error', 'execution_time',
        ];

        $items = \Pramnos\Html\Datatable\Datasource::getList(
            'queueitems',
            $fields,
            false
        );

        $loopCounter = 0;
        if (isset($items['aaData']) && is_array($items['aaData'])) {
            foreach ($items['aaData'] as $data) {
                $taskid = $data[0];

                foreach ($data as $key => $value) {
                    if ($key === 0) {
                        continue;
                    }
                    $data[$key] = '<a href="' . $this->getItemShowUrl($taskid) . '">' . $value . '</a>';
                }

                $data[] = '<a href="' . $this->getItemEditUrl($taskid) . '">Edit</a> '
                    . '<a onclick="return confirm(\'Delete this task?\');" href="'
                    . $this->getItemDeleteUrl($taskid) . '">Delete</a>';

                $items['aaData'][$loopCounter] = $data;
                $loopCounter++;
            }
        }

        return json_encode($items) ?: '{}';
    }

    // ── Configurable URL hooks ────────────────────────────────────────────────

    /**
     * URL for the "show" action in the admin datatable.
     *
     * Default uses the sURL constant (set by the framework bootstrap) and the
     * canonical Queueitems controller path. Override in application subclasses
     * to point to your own controller routes.
     *
     * @param  mixed $taskid
     * @return string
     */
    protected function getItemShowUrl(mixed $taskid): string
    {
        $base = defined('sURL') ? sURL : '';
        return $base . 'Queueitems/show/' . $taskid;
    }

    /**
     * URL for the "edit" action in the admin datatable.
     *
     * @param  mixed $taskid
     * @return string
     */
    protected function getItemEditUrl(mixed $taskid): string
    {
        $base = defined('sURL') ? sURL : '';
        return $base . 'Queueitems/edit/' . $taskid;
    }

    /**
     * URL for the "delete" action in the admin datatable.
     *
     * @param  mixed $taskid
     * @return string
     */
    protected function getItemDeleteUrl(mixed $taskid): string
    {
        $base = defined('sURL') ? sURL : '';
        return $base . 'Queueitems/delete/' . $taskid;
    }
}
