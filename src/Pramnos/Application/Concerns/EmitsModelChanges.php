<?php

declare(strict_types=1);

namespace Pramnos\Application\Concerns;

/**
 * Announces what a model wrote, on the model change feed (`Pramnos\Event\ChangeFeed`).
 *
 * Shared between {@see \Pramnos\Application\Model} and {@see \Pramnos\User\User} — the two
 * classes a save/delete can happen through that are not the same class, because `User`
 * predates `Model` and was never rebuilt onto it. The trait is the one place the actual
 * `ChangeFeed::emit()`/`ModelChange` wiring lives; what differs between the two call sites —
 * the entity's default name, the table it writes to, how to read its own primary key — is
 * three small methods a using class supplies.
 *
 * @see \Pramnos\Event\ChangeFeed
 * @see \Pramnos\Event\ModelChange
 */
trait EmitsModelChanges
{
    /**
     * Whether this instance announces its own changes at all.
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
     * The name the feed uses for this thing. Empty means {@see changeDefaultEntity()}.
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
     * Off, because it is not free: `\Pramnos\Logs\Trace::of(new \Exception())` runs on every
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
     * Suppresses one emission.
     *
     * `OrmModel`'s soft delete performs its work through `parent::_save()`, which would
     * otherwise announce an update where the caller means a delete. It sets this around
     * that call and emits the delete itself.
     *
     * `protected`, not `private`: this trait is shared with {@see \Pramnos\User\User},
     * whose own `(array) $this` → `foreach ($cached as $key => $value) { $this->$key = $value; }`
     * cache round-trip (see its `load()`/`$_usercache`) cannot survive a `private` property at
     * all — the cast mangles a private property's key with a null byte and the declaring
     * class's name, which `$this->$key = …` cannot resolve back to the real property, so
     * PHP's `__set()` catches it instead and the mangled key ends up as a row in `userdetails`.
     * `User` has never declared a `private` property before this trait, which is why nothing
     * surfaced this until now.
     *
     * @var bool
     */
    protected $_suppressChangeEmit = false;

    /**
     * The entity name {@see emitChange()} and {@see changeChannels()} fall back to when
     * {@see $changeEntity} is empty — `Model`'s own name, `'user'` for {@see \Pramnos\User\User}.
     *
     * @return string
     */
    abstract protected function changeDefaultEntity(): string;

    /**
     * The table the change feed records against — `ModelChange::$table`.
     *
     * @return string
     */
    abstract protected function changeTable(): string;

    /**
     * This record's own primary key value, or null before it has one (a create in progress).
     *
     * @return mixed
     */
    abstract protected function changeKey();

    /**
     * Channels a change on this record publishes to, when a broadcasting driver is
     * configured. Local listeners (the feed itself, the changelog) are unaffected by this —
     * it only matters once something crosses a process boundary.
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
        $entity = $this->changeEntity !== '' ? $this->changeEntity : $this->changeDefaultEntity();
        $key    = $this->changeKey();

        if ($key === null || $key === '') {
            return array('private-' . $entity);
        }

        return array('private-' . $entity, 'private-' . $entity . '.' . $key);
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

            \Pramnos\Event\ChangeFeed::emit(
                new \Pramnos\Event\ModelChange(
                    $this->changeEntity !== '' ? $this->changeEntity : $this->changeDefaultEntity(),
                    $key !== null ? $key : $this->changeKey(),
                    $op,
                    $data,
                    $changes,
                    $this->changeChannels($op),
                    $this->broadcastFields,
                    $this->currentChangeUserId(),
                    $this->currentChangeSource(),
                    time(),
                    static::class,
                    $this->changeTable(),
                    $this->captureTrace,
                    // Built here rather than by the listener, because a trace taken later
                    // would describe the listener's stack rather than the save's — which
                    // is the one thing anybody reading it wants.
                    $this->captureTrace
                        ? \Pramnos\Logs\Trace::of(new \Exception())
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
        return \Pramnos\Changelog\ChangelogWriter::currentUserId();
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
        return \Pramnos\Changelog\ChangelogWriter::currentSource();
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
}
