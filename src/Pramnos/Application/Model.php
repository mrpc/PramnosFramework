<?php
namespace Pramnos\Application;
/**
 * @copyright    (c) 2005 - 2026 Yannis - Pastis Glaros
 * @author       Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class Model extends \Pramnos\Framework\Base implements \Pramnos\Application\ApiList\ApiListSource
{
    /**
     * Model name
     * @var string
     */
    protected $modelname = '';
    /**
     * Database table
     * @var string
     */
    protected $_dbtable = null;
    /**
     * Database schema
     * @var string
     */
    protected $_dbschema = null;
    /**
     * Cache key
     * @var string
     */
    protected $_cacheKey = null;
    /**
     * Primary key in database
     * @var string
     */
    protected $_primaryKey  = 'id';
    /**
     * Is the model new?
     * @var boolean
     */
    protected $_isnew       = true;
    /**
     * Array onf json actions, used in getJsonList
     * @var type
     */
    private   $_jsonactions = array();
    /**
     * Initial data loaded from database
     * @var array
     */
    protected $_initialData = array();

    /**
     * Array of last changes
     * @var array
     */
    protected $_lastChanges = array();

    /**
     * Database prefix used for this model
     * @var string
     */
    public $prefix = '';
    /**
     * Reference to the controller calling this model for better communication
     * @var \Pramnos\Application\Controller
     */
    public $controller = null;


    public static $columnCache = array();
    /**
     * SQL error if any
     * @var string
     */
    protected $sqlError = null;
    /**
     * Whether to use cache in list retrievals
     * @var boolean
     */
    protected $useCacheInLists = false;
    /**
     * Time to live for cache in lists (in seconds)
     * @var int
     */
    protected $cacheInListsTime = 60;

    /**
     * Announce saves and deletes on the change feed.
     *
     * Off, so no existing model changes behaviour by upgrading. A model that turns it on
     * emits a {@see \Pramnos\Event\ModelChange} from `_save()` and `_delete()`; what
     * happens to it then is entirely up to whoever is listening.
     *
     * Named `emitChanges` rather than `broadcastChanges` on purpose: the feed is local
     * and works with no broadcasting driver, no Redis and no WebSocket daemon.
     * Broadcasting is one listener among several, and the wrong name here is how somebody
     * concludes an audit log needs a socket.
     *
     * @var bool
     */
    protected $emitChanges = false;

    /**
     * The name the feed uses for this thing. Empty means the model name.
     *
     * A stable, self-describing string — `wcm-device`, not a magic number and not a
     * class name that a refactor can move.
     *
     * @var string
     */
    protected $changeEntity = '';

    /**
     * Fields whose **values** may leave the process in a broadcast.
     *
     * `null` — the default — means a broadcast carries identifiers only: entity, key and
     * operation. The subscriber refetches through the API, where permissions already
     * apply, so there is no way for a column to reach somebody the API would not have
     * shown it to.
     *
     * Setting a list turns values on and makes the model responsible for the choice.
     * Read the channel warning on {@see changeChannels()} first: with values on, a
     * subscriber on the wrong channel is a breach rather than a hint.
     *
     * Local listeners are unaffected — in-process there is no boundary to cross, so they
     * always receive the whole record.
     *
     * @var array|null
     */
    protected $broadcastFields = null;

    /**
     * Fields that are never reported as changed and never leave the process.
     *
     * For columns the application writes to constantly and nobody wants to hear about —
     * a cache blob, a denormalised counter, a serialised view model.
     *
     * @var array
     */
    protected $changeIgnoreFields = array();

    /**
     * An update is only announced when one of these changed. Empty means any field.
     *
     * The cheap way to stop a busy table from filling a log with noise, without having to
     * name every field that *is* noise in {@see $changeIgnoreFields}.
     *
     * @var array
     */
    protected $changeSignificantFields = array();

    /**
     * Capture the stack trace and request context alongside each change.
     *
     * Off, because it is not free: `(new \Exception())->getTraceAsString()` runs on every
     * save that emits, and the reference application pays exactly that on every device
     * write. Turn it on where a change is being chased, and off again afterwards.
     *
     * Only the changelog listener does anything with it, and it keeps traces for days
     * rather than months — see the `changelog` feature.
     *
     * @var bool
     */
    protected $captureTrace = false;

    /**
     * Suppresses one emission. Internal, and deliberately not protected.
     *
     * `OrmModel`'s soft delete performs its work through `parent::_save()`, which would
     * otherwise announce an update where the caller means a delete. It sets this around
     * that call and emits the delete itself.
     *
     * @var bool
     */
    private $_suppressChangeEmit = false;

    /**
     * Class constructor. Sets the model name and the database table
     * @param \Pramnos\Application\Controller Current controller
     * @param string $name Name of model - used automatic table discover
     */
    public function __construct(\Pramnos\Application\Controller $controller,
        $name = '')
    {
        if ($name == '') {
            $name = (new \ReflectionClass($this))->getShortName();
        }
        $this->modelname = $name;

        $this->controller = $controller;
        if ($this->_dbtable === null) {
            $this->_dbtable = '#PREFIX#' . $name . 's';
        }
        $database = \Pramnos\Database\Database::getInstance();
        $this->_dbtable = static::resolveTableName($this->_dbtable, $database->prefix);

        parent::__construct();
    }


    /**
     * The table name this model actually reads and writes.
     *
     * A model names its table in one of two ways, and until now they did not
     * end up in the same place:
     *
     *   - `'#PREFIX#users'` — the token is substituted, giving `pramnos_users`;
     *   - `'mails'` — nothing happened, giving `mails`.
     *
     * Six framework models use the second form. With an empty prefix — the
     * default, and every installation the framework was developed against — the
     * two are identical and the difference is invisible. With a prefix set they
     * are different tables, and the model then used one name in its own SQL and
     * (once the schema builder started prefixing) another through the query
     * builder. Half a model working is harder to diagnose than none of it.
     *
     * So the name is normalised once, here: token substituted, prefix applied,
     * and never applied twice. A schema-qualified name is left alone — on
     * PostgreSQL the schema is the namespace, and prefixing inside it would
     * rename tables the framework addresses by schema everywhere else.
     *
     * @param  string|null $table  As declared by the model
     * @param  string      $prefix Configured prefix, already ending in `_`
     * @return string|null
     */
    public static function resolveTableName($table, string $prefix)
    {
        if ($table === null || $table === '') {
            return $table;
        }

        if (stripos($table, '#PREFIX#') !== false) {
            return str_ireplace('#PREFIX#', $prefix, $table);
        }

        if ($prefix === ''
            || strpos($table, '.') !== false
            || str_starts_with($table, $prefix)) {
            return $table;
        }

        return $prefix . $table;
    }

    /**
     * Set `$_dbtable` when the model computes its table name at runtime.
     *
     * Most models declare `protected $_dbtable` and never need this. Those that
     * work it out — from a tenant, a locale, a constructor argument — override
     * this, and the listing helpers call it before they need the name.
     *
     * It exists because the base used to get the table by calling
     * `$this->load(0)`: not to load anything, but hoping the subclass would set
     * `$_dbtable` as a side effect before failing to find the row with id 0.
     * That coupled table discovery to record loading, ran a pointless query, and
     * — the part that mattered — assumed every subclass's `load()` takes exactly
     * one argument.
     *
     * `load()` is deliberately **not** declared anywhere in this hierarchy.
     * Declaring it would fix that signature for every model: PHP only lets a
     * child *add optional* parameters, so an `abstract load($id)`, a concrete
     * `load($id = null)` and even `load(...$args)` all reject a child written as
     * `load($username, $type)`. Subclasses own that signature; the base owns
     * this one.
     *
     * @return void
     */
    protected function initTable()
    {
    }

    /**
     * Last resort for a model that still sets `$_dbtable` inside `load()`.
     *
     * Kept so that models written against the old behaviour keep working. The
     * call is made only when `load()` can actually accept the single argument
     * the base would pass; a model whose `load()` needs more gets a message
     * naming the class and the fix, instead of an `ArgumentCountError` from a
     * call that should never have been attempted.
     *
     * @throws \LogicException When the table name cannot be determined
     * @return void
     */
    protected function tableFromLegacyLoad()
    {
        $problem = static::class . ' has no $_dbtable. Set it as a property, or'
            . ' override initTable() to set it.';

        if (!method_exists($this, 'load')) {
            throw new \LogicException($problem);
        }

        try {
            $reflection = new \ReflectionMethod($this, 'load');
        } catch (\ReflectionException) {
            throw new \LogicException($problem);
        }

        if ($reflection->getNumberOfRequiredParameters() > 1) {
            throw new \LogicException(
                $problem . ' (Its load() requires '
                . $reflection->getNumberOfRequiredParameters()
                . ' arguments, so the old load(0) fallback cannot be used.)'
            );
        }

        // If it still leaves the table unset, that is not an error to raise
        // here: each caller already decides what an unknown table means — one
        // returns an empty list, the others skip the query. Turning that into an
        // exception would change three public methods for no gain.
        $this->load(0);
    }

    /**
     * This function can run after initial variable setups
     */
    public function __init()
    {
        $this->_dbtable=str_ireplace(
            '#THISPREFIX#', $this->prefix . '_', $this->_dbtable
        );
    }

    /**
     * Get another model (gets it from module)
     * @param string $model
     * @return Model
     */
    public function &getModel($model)
    {
        return $this->controller->getModel($model);
    }

    /**
     * Function to automate saving an object to the database
     * @param string    $table
     * @param string    $key
     * @param boolean   $autoGetValues If true, get all values from $_REQUEST
     * @param boolean   $debug Show debug information (and die)
     * @param boolean   $force Force the save operation
     * @return          Model
     */
    protected function _save($table = NULL, $key = NULL,
        $autoGetValues = false, $debug = false, $force = false)
    {
        $database = \Pramnos\Database\Database::getInstance();
        if ($autoGetValues == true) {
            $request = new \Pramnos\Http\Request();
        }
        if ($table !== NULL && $table != "") {
            $this->_dbtable = $table;
        }
        if ($key !== NULL && $key != "") {
            $this->_primaryKey = $key;
        }
        try {
            $mc = \Pramnos\Debug\DebugBar::getInstance()->getCollector('models');
            if ($mc instanceof \Pramnos\Debug\Collectors\ModelsCollector) {
                $mc->record(static::class, (string) $this->_dbtable, 'save', $this->{$this->_primaryKey} ?? null);
            }
        } catch (\Throwable) {
            // Schema introspection is an optimisation here; without it the caller
            // falls back to the untyped path below.
        }

        if ($debug==true) {
            var_dump($_POST, $this);
        }

        // For existing records, check if there are any changes before saving
        if (!$this->_isnew && !empty($this->_initialData) && $force == false) {
            $changes = $this->getChanges();
            if (empty($changes)) {
                // No changes detected, no need to save
                return $this;
            }
        }

        if ($this->_dbtable != NULL) {
            if ($this->_cacheKey === NULL) {
                $this->_fixDb();
            }
            $itemdata = array();

            if (isset(self::$columnCache[$this->getFullTableName()])) {
                foreach (self::$columnCache[$this->getFullTableName()] as $fields) {
                    if ($fields['Field'] != $this->_primaryKey) {
                        $field = $fields['Field'];
                        if ($fields['Null'] == "NO") {
                            if ($this->$field === NULL) {
                                // The cached half of the same rule — see the uncached branch
                                // below for why a NOT NULL date is omitted rather than emptied.
                                // Two loops, so the fix has to be in both: with a warm column
                                // cache the write took the other path and threw again.
                                if ($this->isTemporalType($fields['Type'])) {
                                    continue;
                                }
                                $this->$field = "";
                            }
                        }
                        if ($autoGetValues == true) {
                            if ($debug == true) {
                                echo "<br />" . $this->$field
                                    . ': '
                                    . $request->get(
                                        $field, $this->$field, 'post'
                                    );
                            }
                            $this->$field = $request->get(
                                $field, $this->$field, 'post'
                            );
                        }
                        $itemdata[] = array(
                            'fieldName' => $fields['Field'],
                            'value'     => $this->$field,
                            'type'      => $this->fieldtype($fields['Type'])
                        );
                    }
                }
            } else {

                if ($database->type == 'postgresql') {
                    if ($this->_dbschema != null) {
                        $schema = $this->_dbschema;
                    } else {
                        $schema = $database->schema;
                    }
                    $sql = "SELECT column_name as \"Field\", "
                    . " CASE WHEN data_type = 'USER-DEFINED' THEN udt_name ELSE data_type END as \"Type\", "
                    . " is_nullable as \"Null\" "
                    . " FROM information_schema.columns "
                    . " WHERE table_schema = '"
                    . $schema
                    . "' AND table_name = '"
                    . str_replace('#PREFIX#', $database->prefix, $this->_dbtable)
                    . "';";
                } else {
                    $sql    = "SHOW COLUMNS FROM `" . $this->getFullTableName() . "`";
                }

                // Deliberately uncached — the `false` is the point of this comment.
                //
                // The read paths below introspect with `query($sql, true, 3600, …)`,
                // so a listing pays for this once an hour rather than once a request.
                // This one is the **write** path, and the asymmetry is not an
                // oversight: a stale schema here means the loop below never sees a
                // newly added column, so `_save()` writes every row without it. That
                // is silent data loss, discovered later and unrecoverable, against a
                // saving of one query per table per request.
                //
                // A stale schema on a read path costs a column missing from a list.
                // Visible, harmless, and fixed by waiting.
                //
                // The key is still built so the two paths can be told apart in the
                // query log, and so anybody enabling this has one less thing to write.
                $cacheKey = "schema_columns_" . $this->getFullTableName();
                $result = $database->query($sql, false, 3600, $cacheKey);
                self::$columnCache[$this->getFullTableName()] = array();
                while ($result->fetch()) {
                    self::$columnCache[$this->getFullTableName()][] = $result->fields;
                    if ($result->fields['Field'] != $this->_primaryKey) {
                        $field = $result->fields['Field'];
                        if ($result->fields['Null'] == "NO") {
                            if ($this->$field === NULL) {
                                /*
                                 * A NOT NULL column with nothing in it becomes `''` — which is a
                                 * fine empty string and an impossible date.
                                 *
                                 * `authserver.roles.created_at` is `NOT NULL DEFAULT
                                 * CURRENT_TIMESTAMP`, so a model that never sets it is asking the
                                 * column's own default to apply. Coercing to `''` asked for the
                                 * timestamp *zero* instead, which strict MySQL and PostgreSQL both
                                 * refuse — so creating a role through its own admin screen threw
                                 * rather than saved, on either backend.
                                 *
                                 * Omitted from the write instead: on an insert the default fills
                                 * it, and on an update the stored value stays, which is what a
                                 * model with no opinion about a column should do.
                                 */
                                if ($this->isTemporalType($result->fields['Type'])) {
                                    continue;
                                }
                                $this->$field = "";
                            }
                        }
                        if ($autoGetValues == true) {
                            if ($debug == true) {
                                echo "<br />" . $this->$field
                                    . ': '
                                    . $request->get(
                                        $field, $this->$field, 'post'
                                    );
                            }
                            $this->$field = $request->get(
                                $field, $this->$field, 'post'
                            );
                        }
                        $itemdata[] = array(
                            'fieldName' => $result->fields['Field'],
                            'value'     => $this->$field,
                            'rawtype'  => $result->fields['Type'],
                            'type'      => $this->fieldtype(
                                $result->fields['Type']
                            )
                        );
                    }
                }
            }
            $primarykey = $this->_primaryKey;
            if ($debug==true) {
                var_dump($itemdata);
            }

            $wasNew = ($this->_isnew == true);

            if ($this->_isnew == true) {

                $this->_isnew = false;
                $result = $database->insertDataToTable(
                    $this->getFullTableName(), $itemdata, $primarykey, $debug
                );
                if ($result==false) {
                    $error = $database->getError();
                    throw new \Exception($error['message']);
                }
                if ($database->type == 'postgresql') {
                    $this->$primarykey = $result->fields[$primarykey] ?? null;
                } else {
                    $this->$primarykey = $database->getInsertId();
                }
                $database->cacheflush($this->_cacheKey);
                
            } else {
                try {
                    $database->updateTableData(
                        $this->getFullTableName(), $itemdata,
                        "`" . $primarykey . "` = '" . $this->$primarykey . "'",
                        $debug
                    );
                } catch (\Throwable $ex) {
                    \Pramnos\Logs\Logger::logError("Error in _save update: " . $ex->getMessage(), $ex);
                    $this->sqlError = $ex->getMessage();
                    return $this;
                }

                // Clear only the specific record's cache, not the entire category
                if (isset($this->$primarykey) && $this->$primarykey !== null) {
                    $database->cacheflush($this->_generateSpecificCacheKey($this->$primarykey));
                } else {
                    // Fallback: if primary key is not available, clear entire category
                    $database->cacheflush($this->_cacheKey);
                }

            }
            
            
            
            // After successful save, update the initial data to match current state
            $this->_initialData = array();
            foreach ($itemdata as $item) {
                $field = $item['fieldName'];
                $this->_initialData[$field] = $this->$field;
            }
            // Also make sure primary key is in initial data
            if (isset($this->$primarykey)) {
                $this->_initialData[$primarykey] = $this->$primarykey;
            }
        }
        if (!isset($changes)) {
            $changes = array();
            // `$itemdata` is built inside the `$this->_dbtable != NULL` block above, so a
            // model without a table never defines it — and this loop then ran over an
            // undefined variable. Harmless while every model declared a table, which is
            // why it survived; a fatal the moment one did not, in the method every save
            // goes through.
            //
            // The empty default rather than a guard around the loop, so `_lastChanges`
            // is an array on every path. A caller reading it does not want to find out
            // that "no table" is the one case where it is not there.
            foreach ($itemdata ?? array() as $item) {
                $field = $item['fieldName'];
                $changes[$field] = array(
                    'old' => null,
                    'new' => $this->$field
                );
            }
        }
        $this->_lastChanges = $changes;

        // Emitted last, after every path that could still have returned early: a save
        // with nothing to change returns above, and so does an update whose statement
        // threw. Announcing a change that did not reach the database is the one failure
        // a feed must not have.
        //
        // Guarded on the table because a model without one never entered the block that
        // builds $itemdata, so there is no record to describe.
        if ($this->_dbtable != NULL) {
            $this->emitChange(
                (isset($wasNew) && $wasNew)
                    ? \Pramnos\Event\ModelChange::CREATED
                    : \Pramnos\Event\ModelChange::UPDATED,
                $changes
            );
        }

        return $this;
    }

    /**
     * Function to get the count of items based on the provided filter, table, and key.

     * @param string $filter
     * @param string $table
     * @param string $key
     * @return integer
     */
    public function getCount($filter = NULL, $table = NULL, $key = NULL)
    {
        $database = \Pramnos\Database\Database::getInstance();
        if ($table !== NULL && $table != "") {
            $this->_dbtable = $table;
        }
        if ($key !== NULL && $key != "") {
            $this->_primaryKey = $key;
        }
        if ($this->_dbtable === NULL) {
            return 0;
        }
        if ($this->_dbtable != NULL) {
            if ($this->_cacheKey === NULL) {
                $this->_fixDb();
            }
            $primarykey = $this->_primaryKey;
            if ($filter === NULL) {
                $filter = "";
            }
            try {
                $result = $database->queryBuilder()
                    ->from($this->getFullTableName())
                    ->select('count(*) as itemscount')
                    ->whereRaw($this->_stripSqlKeyword($filter, 'WHERE'))
                    ->get($this->useCacheInLists, $this->cacheInListsTime, $this->_cacheKey);
            } catch (\Exception $e) {
                \Pramnos\Logs\Logger::logError(
                    'Error executing getCount query: '
                     . $e->getMessage(),
                    $e
                );
                return 0;
            }

            return $result->fields['itemscount'];
        }
        return 0;
    }



    /**
     * Function to automate loading an object from the database
     * @param string $primaryKey
     * @param string $table
     * @param string $key
     * @param boolean   $debug
     * @param boolean   $useCache Use cache?
     * @return Model
     */
    protected function _load($primaryKey, $table = NULL,
        $key = NULL, $debug=false, $useCache = true)
    {
        $database = \Pramnos\Database\Database::getInstance();
        if ($table !== NULL && $table != "") {
            $this->_dbtable = $table;
        }
        if ($key !== NULL && $key != "") {
            $this->_primaryKey = $key;
        }
        try {
            $mc = \Pramnos\Debug\DebugBar::getInstance()->getCollector('models');
            if ($mc instanceof \Pramnos\Debug\Collectors\ModelsCollector) {
                $mc->record(static::class, (string) $this->_dbtable, 'load', $primaryKey);
            }
        } catch (\Throwable) {
            // Schema introspection is an optimisation here; without it the caller
            // falls back to the untyped path below.
        }
        if ($this->_dbtable != NULL) {
            if ($this->_cacheKey === NULL) {
                $this->_fixDb();
            }
            if ($debug === true) {
                // toSql() is useful for debug
                die($database->queryBuilder()->from($this->getFullTableName())->where($this->_primaryKey, $primaryKey)->limit(1)->toSql());
            }
            
            // Use specific cache key that includes the primary key value
            $specificCacheKey = $this->_generateSpecificCacheKey($primaryKey);
            $result = $database->queryBuilder()
                ->from($this->getFullTableName())
                ->where($this->_primaryKey, $primaryKey)
                ->limit(1)
                ->get(false, 600, $specificCacheKey);
            if ($result->numRows != 0) {
                // Reset initial data array
                $this->_initialData = array();
                
                foreach (array_keys($result->fields) as $field) {
                    $this->$field = $result->fields[$field];
                    // Store initial value
                    $this->_initialData[$field] = $result->fields[$field];
                }
                $this->_isnew = false;
            }
        }
        return $this;
    }

    /**
     * Function to automate deleting an object from the database
     * @param integer $primaryKey
     * @param string $table
     * @param string $key
     * @return Model
     */
    protected function _delete($primaryKey, $table = NULL, $key = NULL)
    {
        $database = \Pramnos\Database\Database::getInstance();
        if ($table !== NULL && $table != "") {
            $this->_dbtable = $table;
        }
        if ($key !== NULL && $key != "") {
            $this->_primaryKey = $key;
        }
        if ($this->_dbtable != NULL) {
            if ($this->_cacheKey === NULL) {
                $this->_fixDb();
            }
            if ($database->queryBuilder()->from($this->getFullTableName())->where($this->_primaryKey, $primaryKey)->delete()) {
                // Clear only the specific record's cache, not the entire category
                $database->cacheflush($this->_generateSpecificCacheKey($primaryKey));
                $database->cacheflush($this->_cacheKey);

                // Emitted with the key that was actually deleted, which is not always the
                // one the model holds — `_delete()` takes it as an argument and does not
                // load the row.
                //
                // The record is therefore whatever the model happened to have, and may be
                // empty. It is deliberately **not** loaded first: that is a query on every
                // delete, on the framework's account, to populate a payload the default
                // broadcast does not send. Code that needs full data on delete loads the
                // model before calling this — which is what an application doing so
                // already does.
                $this->emitChange(
                    \Pramnos\Event\ModelChange::DELETED,
                    array(),
                    $primaryKey
                );
            }
        }
        $this->_isnew = true;
        return $this;
    }


    


    /**
     * Similar to getList(), good for pagination
     * @param  int     $items  Number of items by page
     * @param  int     $page   Current page number
     * @param  string  $filter Filter for where statement in database query
     * @param  string  $order  Order for database query
     * @param  string  $table  Database table
     * @param  string  $key    Database primary key
     * @param  boolean $debug  Show debug information
     * @param  string  $join   Join statement for database query
     * @param  string  $queryFields Fields to select in query. If $queryFields is NULL, all fields are selected
     * @param  string  $group  Group by statement for database query
     * @param  boolean $returnAsModels If true, return objects as models, otherwise return as arrays
     * @param  boolean $useGetData If true, use getData() to return data instead of model properties (returning an array)
     * @param  mixed $customGetListMethod if is set, use this method instead of the default getList method
     * @param array  $addedfields If is set, these fields will not be filtered out
     * @return array           Three keys: total, pages, items
     */
    protected function _getPaginated($items=10, $page=1,
        $filter = NULL, $order = NULL, $table = NULL,
        $key = NULL, $debug=false,
        $join = '',
        $queryFields = NULL,
        $group = '', $returnAsModels = true, $useGetData = false,
        $customGetListMethod = false, $addedfields = array())
    {
        if (!is_array($addedfields)) {
            $addedfields = array();
        }  
        $items = abs((int)$items);
        $page-=1;
        $page = abs((int)$page);
        $page = $items * $page;
        if ($table === NULL && $this->_dbtable === NULL) {
            $table = '#PREFIX#' . $this->prefix . '_' . $this->modelname;
        }
        $objects = array();
        $database = \Pramnos\Database\Database::getInstance();
        if ($table !== NULL && $table != "") {
            $this->_dbtable = str_replace(
                "#PREFIX#", $database->prefix, $table
            );
        }
        if ($key !== NULL && $key != "") {
            $this->_primaryKey = $key;
        }
        if ($this->_dbtable === NULL) {
            $this->initTable();
        }
        if ($this->_dbtable === NULL) {
            $this->tableFromLegacyLoad();
        }
        if ($this->_dbtable != NULL) {
            if ($this->_cacheKey === NULL) {
                $this->_fixDb();
            }
            $primarykey = $this->_primaryKey;
            $selectClause = $this->_ensurePrimaryKeyInSelect($queryFields, $primarykey);
            $qb = $database->queryBuilder()
                ->from($this->getFullTableName() . ' a')
                ->select($selectClause);

            if ($join != '') {
                $qb->joinRaw($join);
            }

            if ($filter != '') {
                $qb->whereRaw($this->_stripSqlKeyword($filter, 'WHERE'));
            }

            if ($group != '') {
                $qb->groupByRaw($this->_stripSqlKeyword($group, 'GROUP BY'));
            }

            if ($order != '') {
                $qb->orderByRaw($this->_stripSqlKeyword($order, 'ORDER BY'));
            }

            // Get total count
            if ($group != '') {
                $countQb = clone $qb;
                $countQb->select('1')->clearOrderingAndPaging();
                $countSql = "SELECT COUNT(*) as itemscount FROM (" . $countQb->toSql() . ") as grouped_query";
                $countResult = $database->query($countSql, $this->useCacheInLists, $this->cacheInListsTime, $this->_cacheKey);
            } else {
                $countQb = clone $qb;
                $countQb->select('count(a.' . $primarykey . ') as itemscount')->clearOrderingAndPaging();
                $countResult = $countQb->get($this->useCacheInLists, $this->cacheInListsTime, $this->_cacheKey);
            }

            $totalItems = $countResult->fields['itemscount'] ?? 0;

            if ($totalItems == 0 || $items == 0) {
                $totalPages = 1;
            } else {
                $totalPages = ceil($totalItems / $items);
            }

            // Set limit and offset for the main query
            $qb->limit($items)->offset($page);

            if ($debug == true) {
                die($qb->toSql());
            }

            try {
                $result = $qb->get($this->useCacheInLists, $this->cacheInListsTime, $this->_cacheKey);
                if ($result === false || $result === null) {
                    throw new \Exception("Query failed to execute: " . $qb->toSql());
                }
            } catch (\Throwable $ex) {
                \Pramnos\Logs\Logger::logError("Error in getPaginated query: " . $qb->toSql() . " - " . $ex->getMessage(), $ex);
                throw new \Exception($ex->getMessage(), (int) $ex->getCode(), $ex);
            }

            $class = get_class($this);

            if ($returnAsModels == false && $useGetData == false) {
                $objects = array();
                while ($result->fetch()) {
                    $item = $result->fields;
                    $item = $this->_processJsonFields($item, $join);
                    $objects[] = $item;
                }
                return array(
                    'total'=>$totalItems,
                    'pages'=>$totalPages,
                    'items'=>$objects
                );
            }

            while ($result->fetch()) {
                $objects[$result->fields[$primarykey]] = new $class(
                    $this->controller
                );

                // Reset initial data array for this object
                $objects[$result->fields[$primarykey]]->_initialData = array();

                foreach (array_keys($result->fields) as $field) {
                    $objects[$result->fields[$primarykey]]->$field
                        = $result->fields[$field];
                    // Store initial value
                    $objects[$result->fields[$primarykey]]->_initialData[$field] = $result->fields[$field];
                }
                $objects[$result->fields[$primarykey]]->_isnew = false;
                if ($useGetData == true) {
                    if ($customGetListMethod !== false) {
                        $objects[$result->fields[$primarykey]] = $objects[$result->fields[$primarykey]]->{$customGetListMethod}();
                    } else {
                        $objects[$result->fields[$primarykey]] = $objects[$result->fields[$primarykey]]->getData();
                    }

                    // if queryfields is not null (or *), anything not in queryfields should not be returned
                    if ($queryFields !== NULL && $queryFields != '*' && $queryFields != ''
                        && is_array($objects[$result->fields[$primarykey]])) {
                        $fieldsArray = array_map(
                            fn($f) => $this->_resolveFieldResultName($f),
                            explode(',', $queryFields)
                        );
                        foreach ($objects[$result->fields[$primarykey]] as $key => $value) {
                            if (!in_array($key, $fieldsArray) && !is_array($value) && !in_array($key, $addedfields)) {
                                unset($objects[$result->fields[$primarykey]][$key]);
                            }
                        }
                    }
                }
            }
        }

        return array(
            'total'=>$totalItems,
            'pages'=>$totalPages,
            'items'=>$objects
            );
    }

    /**
     * Get an array of objects from database
     * @param string $filter Filter for where statement in database query
     * @param string $order Order for database query
     * @param string $table
     * @param string $key
     * @param boolean $debug Show debug information
     * @param string $join Join statement for database query
     * @param string $queryFields Fields to select in query. If $queryFields is NULL, all fields are selected
     * @param string $group Group by statement for database query
     * @param boolean $returnAsModels If true, return objects as models, otherwise return as arrays
     * @param boolean $useGetData If true, use getData() to return data instead of model properties (returning an array)
     * @param boolean $displayerroroutput if true, display error output on database query failure
     * @param  mixed $customGetListMethod if is set, use this method instead of the default getList method
     * @param array  $addedfields If is set, these fields will not be filtered out
     * @return array
     */
    public function _getList($filter = NULL, $order = NULL,
        $table = NULL, $key = NULL, $debug=false,
        $join = '',
        $queryFields = NULL,
        $group = '', $returnAsModels = true, $useGetData = false, $displayerroroutput = true,
        $customGetListMethod = false,
        $addedfields = false)
    {
        if ($table === NULL && $this->_dbtable === NULL) {
            $table = '#PREFIX#' . $this->prefix . '_' . $this->modelname;
        }
        if (!is_array($addedfields)) {
            $addedfields = array();
        }   
        $objects = array();
        $database = \Pramnos\Database\Database::getInstance();
        if ($table !== NULL && $table != "") {
            $this->_dbtable = str_replace(
                "#PREFIX#", $database->prefix, $table
            );
        }
        if ($key !== NULL && $key != "") {
            $this->_primaryKey = $key;
        }
        
        if ($this->_dbtable === NULL) {
            $this->initTable();
        }
        if ($this->_dbtable === NULL) {
            $this->tableFromLegacyLoad();
        }
        
        if ($this->_dbtable != NULL) {
            if ($this->_cacheKey === NULL) {
                $this->_fixDb();
            }
            
            $primarykey = $this->_primaryKey;
            $selectClause = $this->_ensurePrimaryKeyInSelect($queryFields, $primarykey);
            $qb = $database->queryBuilder()
                ->from($this->getFullTableName() . ' a')
                ->select($selectClause);

            if ($join != '') {
                $qb->joinRaw($join);
            }

            if ($filter != '') {
                $qb->whereRaw($this->_stripSqlKeyword($filter, 'WHERE'));
            }

            if ($group != '') {
                $qb->groupByRaw($this->_stripSqlKeyword($group, 'GROUP BY'));
            }

            if ($order != '') {
                $qb->orderByRaw($this->_stripSqlKeyword($order, 'ORDER BY'));
            } else {
                if ($join != '') {
                    $qb->orderByRaw('a.' . $primarykey . ' DESC');
                } else {
                    $qb->orderByRaw($primarykey . ' DESC');
                }
            }

            if ($debug == true) {
                die($qb->toSql());
            }
            try {
                $result = $qb->get($this->useCacheInLists, $this->cacheInListsTime, $this->_cacheKey);
            } catch (\Throwable $ex) {
                \Pramnos\Logs\Logger::logError("Error in getList query: " . $qb->toSql() . " - " . $ex->getMessage(), $ex);
                // showError() ends the request. That is defensible for a page — there
                // is nothing useful to render without the list — and wrong for an API,
                // which has an error envelope of its own (ApiListResponse::error) and
                // never reaches it if this exits first. So the page path is unchanged
                // and a client that asked for JSON gets the error the way its caller
                // knows how to report it: sqlError set, empty list returned.
                $application = $this->controller->application ?? null;
                if ($displayerroroutput == true
                    && $application !== null
                    && !$application->clientWantsJson()) {
                    $application->showError($ex->getMessage());
                }
                $this->sqlError = $ex->getMessage();
                return array();
            }
            if ($result === false || $result === null) {
                return array();
            }
            if ($returnAsModels == false && $useGetData == false) {
                $objects = array();
                while ($result->fetch()) {
                    $item = $result->fields;
                    $item = $this->_processJsonFields($item, $join);
                    $objects[] = $item;
                }
                return $objects;
            }
            $class = get_class($this);
            while ($result->fetch()) {

                $objects[$result->fields[$primarykey]]
                    = new $class($this->controller);

                // Reset initial data array for this object
                $objects[$result->fields[$primarykey]]->_initialData = array();

                foreach (array_keys($result->fields) as $field) {
                    $objects[$result->fields[$primarykey]]->$field
                        = $result->fields[$field];
                    // Store initial value
                    $objects[$result->fields[$primarykey]]->_initialData[$field] = $result->fields[$field];
                }
                $objects[$result->fields[$primarykey]]->_isnew = false;
                if ($useGetData == true) {

                    if ($customGetListMethod !== false) {
                        $objects[$result->fields[$primarykey]] = $objects[$result->fields[$primarykey]]->{$customGetListMethod}();
                    } else {
                        $objects[$result->fields[$primarykey]] = $objects[$result->fields[$primarykey]]->getData();
                    }

                    // if queryfields is not null (or *), anything not in queryfields should not be returned
                    if ($queryFields !== NULL && $queryFields != '*' && $queryFields != ''
                        && is_array($objects[$result->fields[$primarykey]])) {
                        $fieldsArray = array_map(
                            fn($f) => $this->_resolveFieldResultName($f),
                            explode(',', $queryFields)
                        );
                        foreach ($objects[$result->fields[$primarykey]] as $key => $value) {
                            if (!in_array($key, $fieldsArray) && !is_array($value) && !in_array($key, $addedfields)) {
                                unset($objects[$result->fields[$primarykey]][$key]);
                            }
                        }
                    }
                }

            }
        }
        return $objects;
    }

    /**
     * Get a list of objects as a json encoded string (DataTables 1.9 legacy format).
     *
     * @deprecated since v1.2 — returns DataTables 1.9 aaData/sEcho format; use
     *             _getApiList() for new code. The PramnosDataTable JS adapter (Phase 17)
     *             consumes _getApiList() output and does not need this method.
     *
     * @param string $filter Filter for sql statement (where)
     * @param string $table Database table
     * @param string $key Primary key in database
     * @return string json encoded string
     */
    public function _getJsonList($filter = NULL, $table = NULL, $key = NULL)
    {
        $lang     = \Pramnos\Translator\Language::getInstance();
        $database = \Pramnos\Database\Database::getInstance();

        if ($table !== NULL && $table != "") {
            $this->_dbtable = str_replace('#PREFIX#', $database->prefix, $table);
        }
        if ($key !== NULL && $key != "") {
            $this->_primaryKey = $key;
        }
        if ($this->_dbtable === NULL) {
            $this->initTable();
        }
        if ($this->_dbtable === NULL) {
            $this->tableFromLegacyLoad();
        }
        if ($this->_dbtable == NULL) {
            return [];
        }
        if ($this->_cacheKey === NULL) {
            $this->_fixDb();
        }

        $filter = ($filter === NULL) ? '' : $filter;

        // Delegate to _getApiList() (clean REST format) to unify code path.
        // Then wrap in DataTables 1.9 aaData/sEcho envelope for BC.
        $apiResult = $this->_getApiList([], '', '', $filter);

        $fields = $apiResult['fields'] ?? [];
        $rows   = $apiResult['data']   ?? [];

        // Convert associative rows → positional arrays (DT 1.9 aaData format).
        $aaData = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $positional = [];
            foreach ($fields as $field) {
                $positional[] = $row[$field] ?? null;
            }
            $aaData[] = $positional;
        }

        $total   = count($aaData);
        $request = \Pramnos\Http\Request::getInstance();

        $objects = [
            'aaData'               => $aaData,
            'sEcho'                => intval($request->get('sEcho') ?? 0),
            'iTotalRecords'        => $total,
            'iTotalDisplayRecords' => $total,
        ];

        // Apply legacy _jsonactions (the reference application BC — action links appended to rows).
        if (is_array($this->_jsonactions) && count($this->_jsonactions) !== 0
            && is_array($objects['aaData'])
        ) {
            $loop = 0;
            foreach ($objects['aaData'] as $data) {
                foreach ($this->_jsonactions as $action) {
                    $targetfield = 0;
                    foreach ($fields as $fieldcount => $field) {
                        if ($field == $action['field']) {
                            $targetfield = $fieldcount;
                        }
                    }

                    if (strpos($action['action'], 'http') === false) {
                        $url = sURL . $this->prefix . '/' . $action['action'] . '/' . $data[$targetfield];
                    } else {
                        $url = $action['action'] . '/' . $data[$targetfield];
                    }

                    $confirm = '';
                    if ($action['confirm'] == true) {
                        $confirm = ' data-confirm="' . htmlspecialchars($lang->_('Are you sure?'), ENT_QUOTES) . '" ';
                    }

                    if ($action['column'] == '') {
                        $title  = ($action['title'] !== '') ? $action['title'] : $action['action'];
                        $data[] = '<a ' . $confirm . ' href="' . $url . '">' . $title . '</a>';
                    } else {
                        foreach ($fields as $fieldcount => $field) {
                            if ($field == $action['column']) {
                                $data[$fieldcount] = '<a ' . $confirm . ' href="' . $url . '">' . $data[$fieldcount] . '</a>';
                            }
                        }
                    }
                }
                $objects['aaData'][$loop] = $data;
                $loop++;
            }
        }

        return json_encode($objects);
    }

    /**
     * Try to find the best _cacheKey to automate database caching
     * @return Model
     */
    /**
     * Strip a leading SQL keyword from a clause string so it can be passed to
     * QB raw methods that add the keyword themselves (whereRaw, orderByRaw, groupByRaw).
     * BC: callers have historically passed "where x=y", "order by x", "group by x".
     */
    /**
     * Extract the result column name from a SQL field expression.
     * Handles: table prefix ("a.id" → "id"), aliases ("id as uid" → "uid"),
     * identifier quotes (backticks, double-quotes, single-quotes), and functions.
     */
    private function _resolveFieldResultName(string $field): string
    {
        return \Pramnos\Application\ApiList\ApiListSqlBuilder::resolveFieldResultName($field);
    }

    private function _stripSqlKeyword(string $sql, string $keyword): string
    {
        return \Pramnos\Application\ApiList\ApiListSqlBuilder::stripSqlKeyword($sql, $keyword);
    }

    /**
     * Ensure the primary key column is always included in the SELECT list so
     * that result rows can be indexed by primary key in the fetch loop.
     * When $queryFields is null or '*' the original value is returned unchanged.
     */
    private function _ensurePrimaryKeyInSelect(?string $queryFields, string $primaryKey): string
    {
        return \Pramnos\Application\ApiList\ApiListSqlBuilder::ensurePrimaryKeyInSelect($queryFields, $primaryKey);
    }

    private function _fixDb()
    {
        $database = \Pramnos\Database\Database::getInstance();
        // Resolve placeholders first so they don't end up in the cache key when DB prefix is empty
        $table = str_replace('#PREFIX#', $database->prefix, $this->_dbtable);
        $table = str_replace('#THISPREFIX#', $this->prefix . '_', $table);
        $this->_cacheKey = str_replace($database->prefix, '', $table);
        if ($this->prefix != "") {
            $this->_cacheKey = str_replace(
                "_" . $this->prefix, '', $this->_cacheKey
            );
            if ($this->_cacheKey != $this->prefix) {
                $this->_cacheKey = str_replace(
                    $this->prefix, '', $this->_cacheKey
                );
            }
        }
        return $this;
    }

    /**
     * Generate a cache key specific to a record by including the primary key value
     * @param mixed $primaryKeyValue The primary key value for the specific record
     * @return string The cache key for the specific record
     */
    protected function _generateSpecificCacheKey($primaryKeyValue)
    {
        if ($this->_cacheKey === NULL) {
            $this->_fixDb();
        }
        return $primaryKeyValue . '-' . $this->_cacheKey;
    }

    /**
     * "Translate" database table field types to types used in Database
     * @param string $type
     * @return string
     */
    /**
     * Is this column a date or a time?
     *
     * Read off the declared type rather than {@see fieldtype()}, which folds `date` and
     * `datetime` into `string` — the two that matter most here.
     */
    private function isTemporalType(string $rawType): bool
    {
        $base = strtolower(trim(explode('(', $rawType)[0]));

        return in_array($base, [
            'date', 'datetime', 'time', 'timestamp', 'timetz', 'timestamptz',
            'year',
        ], true)
            || str_starts_with($base, 'timestamp ')
            || str_starts_with($base, 'time ');
    }

    private function fieldtype($type)
    {
        $type = explode("(", $type);
        $type = strtolower($type[0]);
        switch ($type) {

            case "int":
            case "tinyint":
            case "integer":
            case "smallint":
            case "bigint":
                return "integer";
            case "double":
            case "float":
            case "real":
            case "double precision":
            case "numeric":
            case "decimal":
            case "money":
                return "float";
            case "geometry":
                return "geometry";
            case "boolean":
            case "bool":
                return "boolean";
            case "json":
            case "jsonb":
                return "json";
            case "timestamp":
            case "timestamp without time zone":
            case "timestamp with time zone":
            case "timestamptz":
            case "time":
            case "time without time zone":
            case "time with time zone":
            case "timetz":
                return "timestamp";
            default:
                return "string";
        }
    }

    /**
     * Add a json action for getJsonList
     * @param string  $action
     * @param string  $field
     * @param string  $column
     * @param string  $title
     * @param boolean $confirm
     */
    protected function addJsonAction($action, $field='',
        $column='', $title='',$confirm=false)
    {
        $this->_jsonactions[$action]=array(
            'action'=>$action,
            'field'=>$field,
            'column'=>$column,
            'title'=>$title,
            'confirm'=>$confirm
            );
    }

    /**
     * Returns an array with all useful object data for json encoding
     * @return array
     */
    /**
     * Properties that are the model's machinery rather than its data.
     *
     * Eight of these were the original list. The rest were **excluded by accident**:
     * they are arrays, objects or booleans, and the type filter below happened to drop
     * them. `sqlError` was not — it is a string when a query has failed, so a failed
     * read put its SQL error message into whatever payload the caller was building.
     *
     * Naming them all is what makes {@see $getDataFullFidelity} safe to switch on.
     * Without this list, dropping the type filter would put `_initialData` — a
     * complete second copy of the row — and the controller object into every payload.
     *
     * @var array<string, true>
     */
    private const INTERNAL_PROPERTIES = array(
        '_primaryKey'      => true,
        '_dbtable'         => true,
        'modelname'        => true,
        'prefix'           => true,
        '_dbschema'        => true,
        '_cacheKey'        => true,
        'cacheInListsTime' => true,
        'useCacheInLists'  => true,
        // Excluded by luck until now:
        'sqlError'         => true,
        'controller'       => true,
        '_isnew'           => true,
        '_initialData'     => true,
        '_lastChanges'     => true,
        '_errors'          => true,
        '_messages'        => true,
        '_data'            => true,
        '_parentObject'    => true,
        '_jsonactions'     => true,
        // The change feed's own switches. Every one of them is a scalar or an array a
        // subclass sets, so the type filter would have let `emitChanges`, `changeEntity`
        // and the two lists straight into every payload the moment full fidelity was on.
        // Caught by testEveryDeclaredBasePropertyIsExcluded() rather than by anybody
        // remembering, which is the whole reason that test derives its list from the class.
        'emitChanges'             => true,
        'captureTrace'            => true,
        'changeEntity'            => true,
        'broadcastFields'         => true,
        'changeIgnoreFields'      => true,
        'changeSignificantFields' => true,
        '_suppressChangeEmit'     => true,
        // The switch itself. It is a boolean, so the type filter hid it while the
        // filter existed — turning the filter off would have put
        // `"getDataFullFidelity": true` into every payload the change was meant to
        // improve. Found by a characterization test, not by reading this list, which
        // is why ModelGetDataTest now derives the list from the class rather than
        // trusting anybody to keep it complete by hand.
        'getDataFullFidelity' => true,
    );

    /**
     * Return every column, including `NULL`, booleans and decoded JSON.
     *
     * **On.** Set it to `false` in a base model class to get the historical shape
     * back, byte for byte.
     *
     * The old behaviour kept only `is_numeric()` or `is_string()` values, so a column
     * holding `NULL` was **absent from the payload** rather than `null`, booleans
     * disappeared, and a decoded JSON column disappeared with them.
     *
     * That was measured before flipping, on an application with 54 models where 42
     * reach this through `parent::getData()`: **523 keys across 48 models**, of which
     * **411 were `NULL`**, 53 boolean and 55 array. And the measurement found the
     * argument *for* flipping rather than against. Overrides in that application do
     *
     * ```php
     * $data = parent::getData();
     * $data['reportid'] = (int) $data['reportid'];
     * ```
     *
     * unguarded — so a record with `NULL` in that column raised *Undefined array key*
     * in production and cast the missing value to `0`. The absent key was not a
     * neutral historical quirk; it was producing warnings and wrong numbers.
     *
     * What to check after upgrading, in order of likelihood:
     *
     * - code calling `implode()`, `http_build_query()` or building SQL from the
     *   result — a JSON column now arrives as an array where a scalar was assumed;
     * - clients that reject unknown keys;
     * - anything reading `array_key_exists()` as *"this record has no value"*, which
     *   now means *"this column is null"*.
     *
     * ```php
     * abstract class AppModel extends \Pramnos\Application\Model
     * {
     *     protected $getDataFullFidelity = false;   // the pre-1.2 shape
     * }
     * ```
     *
     * @var bool
     */
    protected $getDataFullFidelity = true;

    /**
     * Return all data as an array.
     *
     * @return array<string, mixed>
     */
    public function getData()
    {
        $source = get_object_vars($this);

        if ($this->getDataFullFidelity) {
            // Columns set on a model that does not declare them as public properties
            // go through Base::__set into `_data`, where get_object_vars() sees the
            // array and not the columns. Such a model gets an **empty** array from the
            // historical path — the columns are there, one level down, and nothing
            // ever looked.
            // Bag first, declared properties second: a declared property is the live
            // value and must win. The reverse lets a stale `_data` entry of the same
            // name shadow it, which presents as a field that stops updating — the
            // hardest kind of staleness to trace, and the order this had at first.
            $source = array_merge($this->_data, $source);

            // One C-level call rather than a PHP loop testing every property.
            // Measured on a model with twelve columns and nineteen internals:
            // 4.109 µs for the loop, **1.287 µs** for this — and `get_object_vars()`
            // alone is 0.949 µs of that, so what remains is 0.34 µs of overhead and
            // there is nothing further to win here.
            //
            // Skipping the merge when `_data` is empty was measured too and gives
            // nothing (1.299 µs): merging an empty array is already cheap, so the
            // branch would be code earning its keep in nobody's benchmark.
            return array_diff_key($source, self::INTERNAL_PROPERTIES);
        }

        // The pre-1.2 shape: only numbers and strings, which silently drops NULL,
        // booleans and decoded JSON columns, and does not read `_data`. The loop
        // survives here because the type test has to run per value — but it now runs
        // over the columns rather than over every property, because the internals are
        // gone before it starts.
        $data = array();
        foreach (array_diff_key($source, self::INTERNAL_PROPERTIES) as $key => $value) {
            if (is_numeric($value) || is_string($value)) {
                $data[$key] = $value;
            }
        }

        return $data;
    }

    /**
     * The channels this model's changes publish on.
     *
     * The default is a per-table firehose plus a per-row channel:
     * `private-wcm-device` and `private-wcm-device.42`.
     *
     * ## Override this in a multi-tenant application
     *
     * The default is right for a single-tenant application and **wrong for a multi-tenant
     * one**. Every subscriber authorized for `private-<entity>` learns that *any* row of
     * the table changed, whoever owns it. With the default identifiers-only payload that
     * leaks existence and timing rather than data — the refetch it prompts is denied by
     * the API — but it is still a leak, and turning {@see $broadcastFields} on makes it a
     * breach.
     *
     * ```php
     * public function changeChannels($op)
     * {
     *     // The row's own owner — never User::getCurrentUser().
     *     return array('private-deya.' . $this->deyaid . '.wcm-device');
     * }
     * ```
     *
     * The tenant key must come from the **row**. Reading it from the session works until
     * a queue worker or a CLI import runs, where there is no session and every change
     * would publish onto one tenant's channel — or none.
     *
     * Whatever this returns needs a matching `ChannelRegistry` rule. A channel nobody is
     * authorized for is a publish into nothing, and it is silent.
     *
     * @param  string $op One of the ModelChange operation constants
     * @return array
     */
    public function changeChannels($op)
    {
        $entity = $this->changeEntity !== '' ? $this->changeEntity : $this->modelname;
        $key    = isset($this->{$this->_primaryKey}) ? $this->{$this->_primaryKey} : null;

        if ($key === null || $key === '') {
            return array('private-' . $entity);
        }

        return array(
            'private-' . $entity,
            'private-' . $entity . '.' . $key,
        );
    }

    /**
     * Announce a change on the feed, if this model announces anything.
     *
     * Everything here is a side effect of the save, never its purpose, so a failure is
     * logged and swallowed. A broadcast that could not be published must not roll back
     * the thing the user actually asked for — the same reasoning
     * {@see \Pramnos\Broadcasting\Broadcastable} already carries.
     *
     * @param  string     $op      One of the ModelChange operation constants
     * @param  array      $changes Field => array('old' => …, 'new' => …)
     * @param  mixed|null $key     Overrides the model's own primary key value
     * @return void
     */
    protected function emitChange($op, array $changes = array(), $key = null)
    {
        // Cheapest possible exit for the overwhelming majority of models, which do not
        // emit: one boolean test, before getData() or anything else is touched.
        if (!$this->emitChanges || $this->_suppressChangeEmit) {
            return;
        }

        try {
            if (!empty($this->changeIgnoreFields)) {
                $changes = array_diff_key(
                    $changes,
                    array_flip($this->changeIgnoreFields)
                );
            }

            // A significance gate on updates only. A create is always worth announcing —
            // there is no previous state for "nothing important changed" to mean — and so
            // is a delete.
            if ($op === \Pramnos\Event\ModelChange::UPDATED
                && !empty($this->changeSignificantFields)
                && array_intersect_key(
                    $changes,
                    array_flip($this->changeSignificantFields)
                ) === array()
            ) {
                return;
            }

            $data = $this->getData();
            if (!empty($this->changeIgnoreFields)) {
                $data = array_diff_key($data, array_flip($this->changeIgnoreFields));
            }

            $primaryKey = $this->_primaryKey;

            \Pramnos\Event\ChangeFeed::emit(
                new \Pramnos\Event\ModelChange(
                    $this->changeEntity !== '' ? $this->changeEntity : $this->modelname,
                    $key !== null
                        ? $key
                        : (isset($this->$primaryKey) ? $this->$primaryKey : null),
                    $op,
                    $data,
                    $changes,
                    $this->changeChannels($op),
                    $this->broadcastFields,
                    $this->currentChangeUserId(),
                    $this->currentChangeSource(),
                    time(),
                    static::class,
                    (string) $this->getFullTableName(),
                    $this->captureTrace,
                    // Built here rather than by the listener, because a trace taken later
                    // would describe the listener's stack rather than the save's — which
                    // is the one thing anybody reading it wants.
                    $this->captureTrace
                        ? (new \Exception())->getTraceAsString()
                        : null
                )
            );
        } catch (\Throwable $ex) {
            \Pramnos\Logs\Logger::logError(
                'Change feed emission failed for ' . static::class . ': '
                . $ex->getMessage(),
                $ex
            );
        }
    }

    /**
     * Who caused this change, when that is knowable.
     *
     * Returns null rather than throwing anywhere it is not: a worker, a console command,
     * an anonymous request. Identity is context the feed carries when it has it, never a
     * precondition for emitting.
     *
     * @return int|null
     */
    protected function currentChangeUserId()
    {
        try {
            $user = \Pramnos\User\User::getCurrentUser();
            if (is_object($user) && isset($user->userid) && (int) $user->userid > 0) {
                return (int) $user->userid;
            }
        } catch (\Throwable) {
            // Asking who is signed in must never be the reason a save reports a problem.
        }

        return null;
    }

    /**
     * Where this change came from: a browser, an API client, or neither.
     *
     * Useful precisely when something is writing rows nobody expected, and the first
     * question is which of the three surfaces did it.
     *
     * @return string
     */
    protected function currentChangeSource()
    {
        if (PHP_SAPI === 'cli' || defined('STDIN')) {
            return \Pramnos\Event\ModelChange::SOURCE_CLI;
        }

        $uri    = strtolower((string) ($_SERVER['REQUEST_URI'] ?? ''));
        $script = strtolower((string) ($_SERVER['SCRIPT_NAME'] ?? ''));

        if (str_contains($uri, '/api/') || str_contains($script, 'api.php')) {
            return \Pramnos\Event\ModelChange::SOURCE_API;
        }

        return \Pramnos\Event\ModelChange::SOURCE_WEB;
    }

    /**
     * Record something a diff cannot express.
     *
     * ```php
     * $device->logEvent('device.assigned_on_finalize', ['tmpdeviceid' => 7]);
     * ```
     *
     * A save writes what changed; this writes what *happened* — an assignment, a
     * replacement, an approval. The two go to different tables with different retentions,
     * because "a column moved" and "somebody did something" are worth keeping for very
     * different lengths of time.
     *
     * The event is a machine code, rendered through i18n at read time by
     * {@see \Pramnos\Changelog\ChangelogRenderer}. `$description` exists for the events
     * no code describes, and should be the exception — prose stored in a row cannot be
     * translated and freezes a wording into history.
     *
     * Requires the `changelog` feature; without it there is nowhere to write and the call
     * does nothing. Unlike {@see emitChange()} it does **not** require `$emitChanges`: a
     * model can record deliberate events without announcing every save.
     *
     * @param  string      $event       Machine code, e.g. `device.assigned_on_finalize`
     * @param  array       $details     Whatever the event carries
     * @param  int         $logtype     The application's own categorisation
     * @param  string|null $description Free text, when no code describes it
     * @return void
     */
    public function logEvent($event, array $details = array(), $logtype = 0, $description = null)
    {
        try {
            \Pramnos\Database\WriteSpool::append(
                \Pramnos\Changelog\ChangelogWriter::EVENTS_TABLE,
                array(
                    'entity'      => $this->changeEntity !== ''
                        ? $this->changeEntity
                        : $this->modelname,
                    'itemid'      => (string) ($this->{$this->_primaryKey} ?? ''),
                    'event'       => $event,
                    'logtype'     => (int) $logtype,
                    'details'     => $details === array() ? null : $details,
                    'description' => $description,
                    'userid'      => $this->currentChangeUserId(),
                    'source'      => $this->currentChangeSource(),
                    'created_at'  => date('c'),
                )
            );
        } catch (\Throwable $ex) {
            // Recording that something happened must not be the reason it fails to.
            \Pramnos\Logs\Logger::logError(
                'Changelog event "' . $event . '" failed for ' . static::class
                . ': ' . $ex->getMessage(),
                $ex
            );
        }
    }

    /**
     * Run something without it announcing itself on the feed.
     *
     * For an operation whose physical shape is not its meaning — a soft delete, which is
     * an UPDATE that means DELETED. The caller emits the truthful event itself.
     *
     * @param  callable $callback
     * @return mixed
     */
    protected function withoutChangeEmission(callable $callback)
    {
        $previous                  = $this->_suppressChangeEmit;
        $this->_suppressChangeEmit = true;

        try {
            return $callback();
        } finally {
            // Restored rather than set false: nesting must not re-enable emission for an
            // outer caller that had deliberately turned it off.
            $this->_suppressChangeEmit = $previous;
        }
    }

    /**
     * Get the last changes made to the model
     * @return array Array of changed fields with their old and new values
     * @example array('field1' => array('old' => 'old_value', 'new' => 'new_value'))
     * @note This function returns the last changes made to the model after saving it to the database.
     *       It provides an array of fields that have changed, along with their old and new values.
     *       This is useful for tracking changes made to the model during the last save operation.
     *       It can be used to determine what fields were modified and their corresponding values before and after the save.
     *       Note that this function only returns the changes from the last save operation.
     *       If you want to get changes made since the model was loaded from the database,
     *       you should use the getChanges() function instead.
     */
    public function  getLastSaveChanges()
    {
        return $this->_lastChanges;
    }

    /**
     * Get changes between current state and initial data
     * @return array Array of changed fields with their old and new values
     * @example array('field1' => array('old' => 'old_value', 'new' => 'new_value'))
     * @note This function compares the current state of the model with the initial data loaded from the database.
     *       It returns an array of fields that have changed, along with their old and new values.
     *       If the model is new or has no initial data, it returns an empty array.
     *       This is useful for tracking changes made to the model after it has been loaded from the database.
     *       It can be used to determine what fields have been modified before saving the model back to the database.
     */
    public function getChanges()
    {
        $changes = array();
        
        // If this is a new model with no initial data, return empty array
        if ($this->_isnew || empty($this->_initialData)) {
            return $changes;
        }
        
        foreach ($this->_initialData as $field => $initialValue) {
            // Check if the field exists and has changed.
            // property_exists() only finds declared properties; ORM models store
            // fields dynamically in $_data via __set/__get — check both.
            if (property_exists($this, $field) || array_key_exists($field, $this->_data)) {
                $currentValue = $this->$field;
                
                // For numeric values, compare using loose comparison to handle type casting
                if (is_numeric($initialValue) && is_numeric($currentValue)) {
                    // Convert both to float for comparison to handle int/float differences
                    if ((float)$initialValue !== (float)$currentValue) {
                        $changes[$field] = array(
                            'old' => $initialValue,
                            'new' => $currentValue
                        );
                    }
                } else {
                    // For non-numeric values, use strict comparison
                    if ($currentValue !== $initialValue) {
                        $changes[$field] = array(
                            'old' => $initialValue,
                            'new' => $currentValue
                        );
                    }
                }
            }
        }
        
        
        return $changes;
    }

    /**
     * Get the fully qualified table name with schema if needed
     * @return string
     */
    public function getFullTableName($tableName = null)
    {
        $database = \Pramnos\Database\Database::getInstance();
        if ($tableName === null) {
            $tableName = $this->_dbtable;
        }
        
        // For PostgreSQL with schema defined, prepend the schema
        if ($database->type == 'postgresql' && $this->_dbschema !== null) {
            return str_replace(
                '#PREFIX#', $database->prefix, $this->_dbschema . '.' . $tableName
            );
        } elseif ($database->type == 'postgresql' && $database->schema != '') {
            return str_replace(
                '#PREFIX#', $database->prefix, $database->schema . '.' . $tableName
            );
        }
        
        /*
         * A `schema.table` name, on a backend that has no schemas.
         *
         * `Role` declares `authserver.roles`, which PostgreSQL reads as a schema and MySQL reads
         * as **another database** — so every `_load()` and `_save()` asked for
         * `pramnos_test.authserver.roles` and threw. The QueryBuilder has resolved this since
         * `from()` was taught to (`authserver.roles` → `prefix_authserver_roles`); the Model's raw
         * SQL never asked, so a model over a schema table worked on one backend and could not
         * read a row on the other.
         *
         * Delegated rather than repeated: the flattening rule — and the prefix guard that keeps
         * `pramnos_pramnos_x` from happening — lives in `SchemaBuilder::resolveTable()`, and two
         * copies of it would eventually disagree.
         */
        if (strpos($tableName, '.') !== false && strpos($tableName, '#PREFIX#') === false) {
            return $database->schema()->resolveTableName($tableName);
        }

        return str_replace(
            '#PREFIX#', $database->prefix, $tableName
        );
    }

    /**
     * Get field types for the current table and any joined tables using the existing fieldtype method
     * @param string $join Optional JOIN clause to include fields from joined tables
     * @return array Array with field names as keys and their types as values
     */
    protected function _getFieldTypes($join = '')
    {
        $fieldTypes = array();
        $tableName = $this->getFullTableName();
        
        // Ensure column cache is populated for main table
        if (!isset(self::$columnCache[$tableName])) {
            $this->_getAllTableFields($join);
        }
        
        // Get field types from main table
        if (isset(self::$columnCache[$tableName])) {
            foreach (self::$columnCache[$tableName] as $fieldInfo) {
                if (isset($fieldInfo['Type']) && isset($fieldInfo['Field'])) {
                    $fieldTypes[$fieldInfo['Field']] = $this->fieldtype($fieldInfo['Type']);
                }
            }
        }
        
        // Get field types from joined tables if JOIN is provided
        if (!empty(trim($join))) {
            $database = \Pramnos\Database\Database::getInstance();
            
            // Parse JOIN clause to extract table names and aliases
            $joinPattern = '/(?:INNER\s+JOIN|LEFT\s+(?:OUTER\s+)?JOIN|RIGHT\s+(?:OUTER\s+)?JOIN|FULL\s+(?:OUTER\s+)?JOIN|CROSS\s+JOIN|JOIN)\s+([`"\w.#]+)\s+(?:AS\s+)?([a-zA-Z_][a-zA-Z0-9_]*)/i';
            
            preg_match_all($joinPattern, $join, $matches, PREG_SET_ORDER);
            
            foreach ($matches as $match) {
                $tableName = trim($match[1], '`"');
                $tableAlias = $match[2];
                
                // Replace prefixes
                $fullTableName = str_replace('#PREFIX#', $database->prefix, $tableName);
                $cacheKey = "schema_columns_{$fullTableName}";
                
                // Ensure this joined table's cache is populated
                if (!isset(self::$columnCache[$cacheKey])) {
                    $this->_getTableFields($fullTableName);
                }
                
                // Get field types from this joined table
                if (isset(self::$columnCache[$cacheKey])) {
                    foreach (self::$columnCache[$cacheKey] as $fieldInfo) {
                        if (isset($fieldInfo['Type']) && isset($fieldInfo['Field'])) {
                            // For joined tables, we need to map both the aliased and non-aliased field names
                            $fieldName = $fieldInfo['Field'];
                            $fieldType = $this->fieldtype($fieldInfo['Type']);
                            
                            // Add both table_alias.field_name and just field_name
                            $fieldTypes[$tableAlias . '.' . $fieldName] = $fieldType;
                            $fieldTypes[$fieldName] = $fieldType;
                        }
                    }
                }
            }
        }
        
        return $fieldTypes;
    }

    /**
     * Process an array of data and decode JSON fields using field type information
     * @param array $data Array of data to process
     * @param string $join Optional JOIN clause to include fields from joined tables
     * @return array Processed data with JSON fields decoded
     */
    protected function _processJsonFields($data, $join = '')
    {
        if (!is_array($data)) {
            return $data;
        }
        
        $fieldTypes = $this->_getFieldTypes($join);
        foreach ($fieldTypes as $fieldName => $fieldType) {
            if ($fieldType === 'json' && isset($data[$fieldName]) && is_string($data[$fieldName]) && !empty($data[$fieldName])) {
                $decoded = json_decode($data[$fieldName], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $data[$fieldName] = $decoded;
                }
            }
        }
        
        return $data;
    }

    /**
     * Get an API-formatted list with pagination, field selection, and search capabilities
     * @param array $fields Array of field names to include in response. If empty, includes all fields
     * @param string|array $search Search parameter: if string, performs global search across all fields; if array, performs field-specific searches ['fieldname' => 'search_term']
     * @param string $order Order by clause (e.g., "field ASC" or "field DESC")
     * @param string|array $filter Additional WHERE clause filter. Two forms accepted:
     *   - string: raw SQL fragment (e.g. "where a.`deyaid` = 5"). Caller is responsible
     *             for safety — use only for app-generated expressions, never raw user input.
     *   - array:  structured condition list, escaped and quoted automatically. Each entry:
     *       Single condition:  ['field' => 'name', 'op' => '=', 'value' => 'x']
     *       OR group:          ['or' => [['field'=>'f1','op'=>'LIKE','value'=>'%x%'], ...]]
     *       Raw fragment:      ['raw' => 'app-generated SQL only']
     *     Supported ops: =  !=  <>  <  >  <=  >=  LIKE  ILIKE  IN  NOT IN  IS NULL  IS NOT NULL
     *     For IN / NOT IN, value must be a non-empty array of scalars.
     *     Unknown fields are silently skipped.
     * @param string $join JOIN clause for complex queries
     * @param string $group GROUP BY clause
     * @param string $table Database table
     * @param string $key Database primary key
     * @param int $page Current page number (1-based, 0 = no pagination)
     * @param int $itemsPerPage Number of items per page (ignored if $page = 0)
     * @param bool $debug Show debug information
     * @param boolean $returnAsModels If true, return objects as models, otherwise return as arrays
     * @param boolean $useGetData If true, use getData() to return data instead of model properties (returning an array)
     * @param  mixed $customGetListMethod if is set, use this method instead of the default getList method
     * @param array $addedfields If is set, these fields will not be filtered out
     * @return array API response with pagination info and data
     */
    /**
     * @param string $format Optional output format. Pass 'datatables' to wrap the response
     *                       in DataTables 2.x format: {draw, data, recordsTotal, recordsFiltered}.
     *                       The JS PramnosDataTable adapter uses this format (Phase 17).
     *                       Default '' returns the standard {data, pagination, fields, debug} envelope.
     */
    public function _getApiList($fields = array(), $search = '',
        $order = '', $filter = '', $join = '', $group = '',
        $table = null, $key = null,
        $page = 0, $itemsPerPage = 10, $debug = false, $returnAsModels = false, $useGetData = false,
        $customGetListMethod = false, $addedfields = false, $format = '')
    {
        // Delegates to the shared list-query engine; Model satisfies ApiListSource
        // (see the apiList* methods below). Behaviour is unchanged — the former
        // in-line orchestration now lives in ApiListQuery so User can share it.
        return \Pramnos\Application\ApiList\ApiListQuery::run(
            $this, $fields, $search, $order, $filter, $join, $group, $table, $key,
            $page, $itemsPerPage, $debug, $returnAsModels, $useGetData,
            $customGetListMethod, $addedfields, $format
        );
    }

    // ── ApiListSource — expose list internals to the shared ApiListQuery engine ──

    /**
     * {@inheritDoc}
     * @param string $join
     * @return array
     */
    public function apiListSchemaFields($join = ''): array
    {
        return $this->_getAllTableFields($join);
    }

    /**
     * {@inheritDoc} A generic model has no curated subset — its default is its
     * full schema, exactly as the pre-extraction _getApiList() behaved.
     * @param string $join
     * @return array
     */
    public function apiListDefaultFields($join = ''): array
    {
        return $this->_getAllTableFields($join);
    }

    /**
     * {@inheritDoc}
     * @return string
     */
    public function apiListPrimaryKey(): string
    {
        return $this->_primaryKey;
    }

    /**
     * {@inheritDoc}
     * @return string
     */
    public function apiListSearchConditions(array $validFields, $globalSearch, array $fieldSearches, $join): string
    {
        return $this->_buildSearchConditions($validFields, $globalSearch, $fieldSearches, $join);
    }

    /**
     * {@inheritDoc}
     * @return array
     */
    public function apiListPaginate(
        $itemsPerPage, $page, $filter, $order, $table, $key, $debug,
        $join, $selectFields, $group, $returnAsModels, $useGetData, $customGetListMethod, $addedfields
    ): array {
        return $this->_getPaginated(
            $itemsPerPage, $page, $filter, $order, $table, $key, $debug,
            $join, $selectFields, $group, $returnAsModels, $useGetData, $customGetListMethod, $addedfields
        );
    }

    /**
     * {@inheritDoc} Hardcodes the legacy $displayerroroutput flag to false so a
     * fetch error surfaces through {@see self::apiListLastError()} (sqlError)
     * rather than being echoed — matching the previous _getApiList() behaviour.
     * @return mixed
     */
    public function apiListFetchAll(
        $filter, $order, $table, $key, $debug,
        $join, $selectFields, $group, $returnAsModels, $useGetData, $customGetListMethod, $addedfields
    ) {
        return $this->_getList(
            $filter, $order, $table, $key, $debug,
            $join, $selectFields, $group, $returnAsModels, $useGetData, false,
            $customGetListMethod, $addedfields
        );
    }

    /**
     * {@inheritDoc}
     * @return array
     */
    public function apiListProcessRow(array $row, $join): array
    {
        return $this->_processJsonFields($row, $join);
    }

    /**
     * {@inheritDoc}
     * @return mixed
     */
    public function apiListLastError()
    {
        return $this->sqlError;
    }

    /**
     * {@inheritDoc}
     * @return int
     */
    public function apiListRecordsTotal($baseFilter, $table, $key, $join, $selectFields, $group, $addedfields): int
    {
        return $this->_datatablesRecordsTotal($baseFilter, $table, $key, $join, $selectFields, $group, $addedfields);
    }

    /**
     * Count the rows matching a base filter WITHOUT any search conditions — the
     * value DataTables expects in `recordsTotal` (the grand total before the
     * search box is applied, with any mandatory $filter/$join/$group still in
     * effect).
     *
     * Reuses {@see self::_getPaginated()} with a single-row window so the count
     * SQL is built identically to the list query (same table/join/group/driver
     * quirks) rather than duplicating the counting logic. Only the returned
     * 'total' — the count of all matching rows — is used; the one fetched row is
     * discarded.
     *
     * @param string       $baseFilter   Filter WITHOUT search (may be '').
     * @param string|null  $table        Table override, as passed to _getApiList.
     * @param string|null  $key          Primary-key override.
     * @param string       $join         Raw JOIN clause.
     * @param string|null  $selectFields Select clause built for the list query.
     * @param string       $group        GROUP BY clause.
     * @param mixed        $addedfields  Extra fields (normalised by _getPaginated).
     * @return int The unfiltered (search-less) row count.
     */
    protected function _datatablesRecordsTotal(
        $baseFilter, $table, $key, $join, $selectFields, $group, $addedfields
    ): int {
        $counted = $this->_getPaginated(
            1, 1, $baseFilter, '', $table, $key, false,
            $join, $selectFields, $group, false, false, false, $addedfields
        );
        return (int)($counted['total'] ?? 0);
    }
    
    /**
     * Get all table fields for the current model
     * @param string $join Optional JOIN clause to include fields from joined tables
     * @return array Array of field names (includes table.field format for joined tables)
     */
    private function _getAllTableFields($join = '')
    {
        $database = \Pramnos\Database\Database::getInstance();
        $fields = array();
        $tableName = $this->getFullTableName();
        $cacheKey = "schema_columns_{$tableName}";
        
        // Get main table fields
        if (isset(self::$columnCache[$this->getFullTableName()])) {
            foreach (self::$columnCache[$this->getFullTableName()] as $fieldInfo) {
                $fields[] = $fieldInfo['Field'];
            }
        } else {
            if ($database->type == 'postgresql') {
                if ($this->_dbschema != null) {
                    $schema = $this->_dbschema;
                } else {
                    $schema = $database->schema;
                }
                $sql = "SELECT column_name as \"Field\", "
                    . " CASE WHEN data_type = 'USER-DEFINED' THEN udt_name ELSE data_type END as \"Type\", "
                    . " is_nullable as \"Null\" "
                    . " FROM information_schema.columns "
                    . " WHERE table_schema = '"
                    . $schema
                    . "' AND table_name = '"
                    . str_replace('#PREFIX#', $database->prefix, $this->_dbtable)
                    . "';";
            } else {
                $sql = "SHOW COLUMNS FROM `" . $tableName . "`";
            }
            
            $result = $database->query($sql, true, 3600, $cacheKey);
            while ($result->fetch()) {
                $fields[] = $result->fields['Field'];
                // Cache the results
                if (!isset(self::$columnCache[$this->getFullTableName()])) {
                    self::$columnCache[$this->getFullTableName()] = array();
                }
                self::$columnCache[$this->getFullTableName()][] = $result->fields;
            }
        }
        
        // If join is provided, extract and get fields from joined tables
        if (!empty(trim($join))) {
            $joinedFields = $this->_getJoinedTableFields($join);
            $fields = array_merge($fields, $joinedFields);
        }
        
        return $fields;
    }
    
    /**
     * Extract table names from JOIN clause and get their fields
     * @param string $join JOIN clause
     * @return array Array of field names without table prefixes (e.g., 'field_name')
     */
    private function _getJoinedTableFields($join)
    {
        $database = \Pramnos\Database\Database::getInstance();
        $fields = array();
        
        // Parse JOIN clause to extract table names and aliases
        // Support various JOIN types: INNER JOIN, LEFT JOIN, RIGHT JOIN, etc.
        $joinPattern = '/(?:INNER\s+JOIN|LEFT\s+(?:OUTER\s+)?JOIN|RIGHT\s+(?:OUTER\s+)?JOIN|FULL\s+(?:OUTER\s+)?JOIN|CROSS\s+JOIN|JOIN)\s+([`"\w.#]+)\s+(?:AS\s+)?([a-zA-Z_][a-zA-Z0-9_]*)/i';
        
        preg_match_all($joinPattern, $join, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $tableName = trim($match[1], '`"');
            $tableAlias = $match[2];
            
            // Replace prefixes
            $fullTableName = str_replace('#PREFIX#', $database->prefix, $tableName);
            
            // Get fields for this joined table
            $tableFields = $this->_getTableFields($fullTableName);
            
            // Add fields without table alias prefix
            foreach ($tableFields as $field) {
                $fields[] = $tableAlias . '.' . $field;
            }
        }
        
        return $fields;
    }
    
    /**
     * Get fields for a specific table
     * @param string $tableName Full table name
     * @return array Array of field names
     */
    private function _getTableFields($tableName)
    {
        $database = \Pramnos\Database\Database::getInstance();
        $fields = array();
        
        // Check cache first
        $cacheKey = $tableName;
        if (isset(self::$columnCache[$cacheKey])) {
            foreach (self::$columnCache[$cacheKey] as $fieldInfo) {
                $fields[] = $fieldInfo['Field'];
            }
            return $fields;
        }
        
        try {
            if ($database->type == 'postgresql') {
                // For PostgreSQL, we need to handle schema
                $parts = explode('.', $tableName);
                if (count($parts) === 2) {
                    $schema = $parts[0];
                    $table = $parts[1];
                } else {
                    $schema = $this->_dbschema ?: $database->schema;
                    $table = $tableName;
                }
                
                $sql = "SELECT column_name as \"Field\", "
                    . " CASE WHEN data_type = 'USER-DEFINED' THEN udt_name ELSE data_type END as \"Type\", "
                    . " is_nullable as \"Null\" "
                    . " FROM information_schema.columns "
                    . " WHERE table_schema = '" . $schema . "'"
                    . " AND table_name = '" . $table . "';";
            } else {
                $sql = "SHOW COLUMNS FROM `" . $tableName . "`";
            }
            
            $cacheKey = "schema_columns_{$tableName}";
            $result = $database->query($sql, true, 3600, $cacheKey);
            
            // Initialize cache for this table
            self::$columnCache[$cacheKey] = array();
            
            while ($result->fetch()) {
                $fields[] = $result->fields['Field'];
                self::$columnCache[$cacheKey][] = $result->fields;
            }
        } catch (\Exception $e) {
            // If we can't get fields for joined table, log error and continue
            \Pramnos\Logs\Logger::logError(
                'Could not get fields for joined table: ' . $tableName . ' - ' . $e->getMessage(),
                $e
            );
        }
        
        return $fields;
    }
    
    /**
     * Build SELECT fields clause with proper table aliases
     * @param array $fields Array of field names
     * @param string $join JOIN clause to determine if table alias is needed
     * @return string Comma-separated field list for SELECT
     */
    private function _buildSelectFields($fields, $join)
    {
        return \Pramnos\Application\ApiList\ApiListSqlBuilder::buildSelectFields($fields, $join);
    }
    
    /**
     * Build search conditions for WHERE clause
     * @param array $fields Available fields for searching
     * @param string $globalSearch Global search term
     * @param array $fieldSearches Field-specific searches
     * @param string $join JOIN clause to determine if table alias is needed
     * @return string WHERE conditions for search
     */
    private function _isNumericColumnType($targetField, $join = '')
    {
        $database = \Pramnos\Database\Database::getInstance();
        $alias = 'a';
        $fieldName = $targetField;
        
        if (strpos($targetField, '.') !== false) {
            $parts = explode('.', $targetField);
            if (count($parts) === 2) {
                $alias = $parts[0];
                $fieldName = $parts[1];
            }
        }
        
        // Remove backticks/quotes from $fieldName for checking (e.g. `deyacode`)
        $fieldName = trim($fieldName, '`"');
        
        $cacheKey = null;
        if ($alias === 'a') {
            $cacheKey = $this->getFullTableName();
        } else {
            // Parse join to find the table name for this alias
            $joinPattern = '/(?:INNER\s+JOIN|LEFT\s+(?:OUTER\s+)?JOIN|RIGHT\s+(?:OUTER\s+)?JOIN|FULL\s+(?:OUTER\s+)?JOIN|CROSS\s+JOIN|JOIN)\s+([`"\w.#]+)\s+(?:AS\s+)?([a-zA-Z_][a-zA-Z0-9_]*)/i';
            if (preg_match_all($joinPattern, $join, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    if ($match[2] === $alias) {
                        $tableName = trim($match[1], '`"');
                        $fullTableName = str_replace('#PREFIX#', $database->prefix, $tableName);
                        $cacheKey = "schema_columns_{$fullTableName}";
                        break;
                    }
                }
            }
        }
        
        if ($cacheKey === null || !isset(self::$columnCache[$cacheKey])) {
            return false;
        }
        
        foreach (self::$columnCache[$cacheKey] as $fieldInfo) {
            // Remove backticks/quotes from cached field name just in case
            $cachedField = trim($fieldInfo['Field'], '`"');
            if ($cachedField === $fieldName) {
                $type = strtolower($fieldInfo['Type']);
                return (bool) preg_match('/^(int|bigint|smallint|tinyint|mediumint|integer|numeric|serial|bigserial|decimal)/', $type);
            }
        }
        return false;
    }

    private function _buildSearchConditions($fields, $globalSearch, $fieldSearches, $join)
    {
        $database = \Pramnos\Database\Database::getInstance();
        $conditions = array();
        $hasJoin = !empty(trim($join));
        
        // Create a mapping of field names without table prefixes to their full references
        $fieldMapping = array();
        foreach ($fields as $field) {
            if (strpos($field, '.') !== false) {
                // Extract field name after the dot
                $fieldName = substr($field, strrpos($field, '.') + 1);
                $fieldMapping[$fieldName] = $field;
            } else {
                $fieldMapping[$field] = $field;
            }
        }
        
        // Global search across all fields
        if (!empty($globalSearch) && trim($globalSearch) != '') {
            $globalConditions = array();
            foreach ($fields as $field) {
                $fieldRef = $field;
                if (strpos($field, '.') === false && $hasJoin) {
                    $fieldRef = 'a.' . ($database->type == 'postgresql' ? '"' . $field . '"' : '`' . $field . '`');
                } elseif (strpos($field, '.') === false) {
                    $fieldRef = ($database->type == 'postgresql' ? '"' . $field . '"' : '`' . $field . '`');
                }

                if (strpos($globalSearch, '%') !== false) {
                    // If search term already contains wildcards, use it directly
                    $globalSearch = $database->prepareInput($globalSearch);
                } else {
                    // Otherwise, prepare it for LIKE search
                    $globalSearch = $database->prepareInput('%' . $globalSearch . '%');
                }
                if (is_string($globalSearch)) {
                    // Split search term into words
                    $words = preg_split('/\s+/', $globalSearch, -1, PREG_SPLIT_NO_EMPTY);
                    $processedWords = array();
                    foreach ($words as $word) {
                        // Detect if word has % at start/end
                        $prefix = (strpos($word, '%') === 0) ? '%' : '';
                        $suffix = (strrpos($word, '%') === strlen($word) - 1) ? '%' : '';
                        // Remove % for processing
                        $cleanWord = trim($word, '%');
                        $lastChar = mb_substr($cleanWord, -1, 1, 'UTF-8');
                        if ($lastChar === 'ς' || $lastChar === 'σ') {
                            $cleanWord = mb_substr($cleanWord, 0, mb_strlen($cleanWord, 'UTF-8') - 1, 'UTF-8');
                        }
                        // Re-add % only where they were
                        $processedWords[] = $prefix . $cleanWord . $suffix;
                    }
                    $globalSearch = implode(' ', $processedWords);
                }
                
                if ($database->type == 'postgresql') {
                    if (\Pramnos\General\StringHelper::containsGreekCharacters($globalSearch)) {
                        $globalConditions[] = 'unaccent(CAST(' . $fieldRef . ' AS TEXT)) ILIKE unaccent(\'' . $database->prepareInput($globalSearch) . '\')';
                    } else {
                        $globalConditions[] = 'CAST(' . $fieldRef . ' AS TEXT) ILIKE \'' . $database->prepareInput($globalSearch) . '\'';
                    }
                } else {
                    $globalConditions[] = $fieldRef . ' LIKE \'' . $database->prepareInput($globalSearch) . '\'';
                }
            }
            if (!empty($globalConditions)) {
                $conditions[] = '(' . implode(' OR ', $globalConditions) . ')';
            }
        }
        
        // Field-specific searches
        foreach ($fieldSearches as $searchField => $searchTerm) {
            if (is_string($searchTerm) && trim($searchTerm) == '') {
                continue;
            }
            
            
            $targetField = null;
            
            // First try exact match
            if (in_array($searchField, $fields)) {
                $targetField = $searchField;
            } else {
                // Try to find field by name without table prefix
                if (isset($fieldMapping[$searchField])) {
                    $targetField = $fieldMapping[$searchField];
                }
            }
            
            if ($targetField === null) {
                continue; // Skip fields that don't exist
            }
            
            $fieldRef = $targetField;
            if (strpos($targetField, '.') === false && $hasJoin) {
                $fieldRef = 'a.' . ($database->type == 'postgresql' ? '"' . $targetField . '"' : '`' . $targetField . '`');
            } elseif (strpos($targetField, '.') === false) {
                $fieldRef = ($database->type == 'postgresql' ? '"' . $targetField . '"' : '`' . $targetField . '`');
            }
            $bool = false;
            $boolValue = 0;
            $isNumeric = false;
            $numericValue = 0;
            if (is_bool($searchTerm) || $searchTerm == 'true' || $searchTerm == 'false') {
                $bool = true;
                $boolValue = ($searchTerm === true || $searchTerm === 'true') ? 1 : 0;
            } elseif (
                $this->_isNumericColumnType($targetField, $join)
                && (is_int($searchTerm) || (is_string($searchTerm) && strlen($searchTerm) > 0 && ctype_digit($searchTerm)))
            ) {
                // Numeric column + numeric value: use exact match to avoid LIKE '%9%' matching 9, 19, 29, etc.
                $isNumeric = true;
                $numericValue = (int) $searchTerm;
            } else {
                if (strpos($searchTerm, '%') !== false) {
                    // If search term already contains wildcards, use it directly
                    $searchTerm = $database->prepareInput($searchTerm);
                } else {
                    // Otherwise, prepare it for LIKE search
                    $searchTerm = $database->prepareInput('%' . $searchTerm . '%');
                }
                if (is_string($searchTerm)) {
                    // Split search term into words
                    $words = preg_split('/\s+/', $searchTerm, -1, PREG_SPLIT_NO_EMPTY);
                    $processedWords = array();
                    foreach ($words as $word) {
                        // Detect if word has % at start/end
                        $prefix = (strpos($word, '%') === 0) ? '%' : '';
                        $suffix = (strrpos($word, '%') === strlen($word) - 1) ? '%' : '';
                        // Remove % for processing
                        $cleanWord = trim($word, '%');
                        $lastChar = mb_substr($cleanWord, -1, 1, 'UTF-8');
                        if ($lastChar === 'ς' || $lastChar === 'σ') {
                            $cleanWord = mb_substr($cleanWord, 0, mb_strlen($cleanWord, 'UTF-8') - 1, 'UTF-8');
                        }
                        // Re-add % only where they were
                        $processedWords[] = $prefix . $cleanWord . $suffix;
                    }
                    $searchTerm = implode(' ', $processedWords);
                }
            }



            if ($database->type == 'postgresql') {
                if ($bool) {
                    $conditions[] = $fieldRef . ' = '. $boolValue;
                } elseif ($isNumeric) {
                    $conditions[] = $fieldRef . ' = ' . $numericValue;
                } elseif (\Pramnos\General\StringHelper::containsGreekCharacters($searchTerm)) {
                    $conditions[] = 'unaccent(CAST(' . $fieldRef . ' AS TEXT)) ILIKE unaccent(\'' . $database->prepareInput($searchTerm) . '\')';
                } else {
                    $conditions[] = 'CAST(' . $fieldRef . ' AS TEXT) ILIKE \'' . $database->prepareInput($searchTerm) . '\'';
                }
            } else {
                if ($isNumeric) {
                    $conditions[] = $fieldRef . ' = ' . $numericValue;
                } else {
                    $conditions[] = $fieldRef . ' LIKE \'' . $database->prepareInput($searchTerm) . '\'';
                }
            }
        }
        
        return implode(' AND ', $conditions);
    }
    
    /**
     * Validate and build ORDER BY clause with field validation and ASC/DESC handling
     * @param string $order Order specification (e.g., "field1,-field2,+field3")
     * @param array $availableFields Array of valid field names
     * @param string $join JOIN clause to determine if table alias is needed
     * @return string Validated ORDER BY clause
     */
    private function _validateAndBuildOrder($order, $availableFields, $join)
    {
        return \Pramnos\Application\ApiList\ApiListSqlBuilder::validateAndBuildOrder($order, $availableFields, $join, $this->_primaryKey);
    }
    
    

    /**
     * Build a safe SQL WHERE fragment from a structured conditions array.
     *
     * Top-level conditions are joined with AND.
     * Each entry is either:
     *
     * A single condition:
     *   ['field' => 'name', 'op' => '=', 'value' => 'x']
     *   Supported ops: = != <> < > <= >= LIKE ILIKE IN NOT IN IS NULL IS NOT NULL
     *   For IN / NOT IN, value must be a non-empty array of scalars.
     *   IS NULL / IS NOT NULL require no value key.
     *
     * An OR group (conditions inside joined with OR, wrapped in parens):
     *   ['or' => [
     *       ['field' => 'firstname', 'op' => 'LIKE', 'value' => '%foo%'],
     *       ['field' => 'lastname',  'op' => 'LIKE', 'value' => '%foo%'],
     *   ]]
     *
     * A raw SQL fragment (caller is responsible for safety — use only for
     * app-generated expressions such as integer ID comparisons, never user input):
     *   ['raw' => "a.`locationid` IN (1,2,3)"]
     *
     * Fields are validated against $availableFields and properly quoted.
     * Values are escaped via prepareInput(). Unknown fields are silently skipped.
     *
     * @param array  $conditions      Structured conditions
     * @param array  $availableFields Whitelist of valid field names
     * @param string $join            JOIN clause (used to decide table alias quoting)
     * @return string Raw SQL WHERE body (without the WHERE keyword)
     */
    private function _buildFilterFromConditions(array $conditions, array $availableFields, string $join = ''): string
    {
        return \Pramnos\Application\ApiList\ApiListSqlBuilder::buildFilterFromConditions($conditions, $availableFields, $join);
    }

    /**
     * Build a single SQL condition expression from a condition array.
     * Returns null if the condition is invalid or the field is unknown.
     *
     * @param array  $condition
     * @param array  $availableFields
     * @param array  $fieldMapping     field-name → full reference map
     * @param bool   $hasJoin
     * @param object $database
     * @return string|null
     */
    /**
     * Combine base filter with search conditions
     * @param string $baseFilter Base WHERE filter
     * @param string $searchConditions Search conditions
     * @return string Combined filter
     */
    private function _combineFilters($baseFilter, $searchConditions)
    {
        return \Pramnos\Application\ApiList\ApiListSqlBuilder::combineFilters($baseFilter, $searchConditions);
    }

}
