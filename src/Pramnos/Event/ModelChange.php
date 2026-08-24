<?php

declare(strict_types=1);

namespace Pramnos\Event;

/**
 * One change to one record, as it happened.
 *
 * Emitted by {@see \Pramnos\Application\Model::emitChange()} and delivered through
 * {@see ChangeFeed}. Listeners turn it into whatever they are for — a broadcast on a
 * channel, a row in a changelog, a message on a queue.
 *
 * ## Why this is a class and not an array
 *
 * Every listener would otherwise index it by string key, and a typo reads as `null`
 * rather than failing. There are two listeners today and there will be more; the keys
 * are a contract between them, so they are declared once here.
 *
 * ## Why the channels and the broadcast allow-list live on the value object
 *
 * Both are resolved from the model at emit time and carried, rather than being looked up
 * later from a model reference. The alternative means holding a live model past the save
 * — which is the failure {@see \Pramnos\Broadcasting\QueuedBroadcastableEvent} documents:
 * a listener running later rebuilds a stale copy of a row that may no longer exist. It is
 * the same trade {@see \Pramnos\Broadcasting\BroadcastableEvent} already makes by naming
 * the channel next to the data.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
final class ModelChange
{
    /** A record that did not exist before this save. */
    public const CREATED = 'created';

    /** A record that existed and has at least one changed field. */
    public const UPDATED = 'updated';

    /** A record that has been removed. */
    public const DELETED = 'deleted';

    /** Emitted from a web request. */
    public const SOURCE_WEB = 'web';

    /** Emitted from an API request. */
    public const SOURCE_API = 'api';

    /** Emitted from a console command, worker or daemon. */
    public const SOURCE_CLI = 'cli';

    /**
     * @param string                                  $entity          Application's name for the thing, e.g. `wcm-device`
     * @param string|int|null                         $key             Primary key value; null when a delete had none
     * @param string                                  $op              One of CREATED / UPDATED / DELETED
     * @param array<string, mixed>                    $data            The record, as `getData()` saw it. In-process only.
     * @param array<string, array{old: mixed, new: mixed}> $changes    Changed fields, ignore-list already removed
     * @param list<string>                            $channels        Channels resolved from the model at emit time
     * @param list<string>|null                       $broadcastFields Fields whose values may be broadcast; null = identifiers only
     * @param int|null                                $userid          Who caused it, when known
     * @param string                                  $source          One of the SOURCE_* constants
     * @param int                                     $at              Unix timestamp
     * @param class-string                            $model           The model class that emitted it
     * @param string                                  $table           Fully-qualified table name
     */
    public function __construct(
        public readonly string $entity,
        public readonly string|int|null $key,
        public readonly string $op,
        public readonly array $data,
        public readonly array $changes,
        public readonly array $channels,
        public readonly ?array $broadcastFields,
        public readonly ?int $userid,
        public readonly string $source,
        public readonly int $at,
        public readonly string $model,
        public readonly string $table,
    ) {
    }

    /**
     * Did this change touch the named field?
     */
    public function has(string $field): bool
    {
        return array_key_exists($field, $this->changes);
    }

    /**
     * The record, reduced to the named fields.
     *
     * Fields absent from the record are absent from the result rather than present and
     * null: a caller filtering a payload is asking for what exists, and inventing keys
     * would put nulls on a wire that nothing put there.
     *
     * @param  list<string>        $fields
     * @return array<string, mixed>
     */
    public function only(array $fields): array
    {
        return array_intersect_key($this->data, array_flip($fields));
    }

    /**
     * The record, without the named fields.
     *
     * @param  list<string>        $fields
     * @return array<string, mixed>
     */
    public function except(array $fields): array
    {
        return array_diff_key($this->data, array_flip($fields));
    }

    /**
     * The changed fields, reduced to the named ones.
     *
     * @param  list<string>                                 $fields
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function changesOnly(array $fields): array
    {
        return array_intersect_key($this->changes, array_flip($fields));
    }

    /**
     * Everything, as a plain array.
     *
     * For a listener that has to serialise this — a queue payload, a log line. Note that
     * `data` is the whole record: in-process that is what listeners want, and anything
     * putting it on a wire is responsible for filtering it first.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'entity'          => $this->entity,
            'key'             => $this->key,
            'op'              => $this->op,
            'data'            => $this->data,
            'changes'         => $this->changes,
            'channels'        => $this->channels,
            'broadcastFields' => $this->broadcastFields,
            'userid'          => $this->userid,
            'source'          => $this->source,
            'at'              => $this->at,
            'model'           => $this->model,
            'table'           => $this->table,
        ];
    }
}
