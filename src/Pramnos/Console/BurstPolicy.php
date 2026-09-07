<?php

declare(strict_types=1);

namespace Pramnos\Console;

/**
 * How many workers a queue should have right now — arithmetic, and nothing else.
 *
 * A **pure** policy: floor, ceiling, running count, backlog, thresholds and load in, a
 * number out. No queue, no database, no process table, no clock. That is not tidiness — it
 * is the only way every branch of a scaling decision can be tested, and a scaling decision
 * that cannot be tested is one nobody dares change. Contributed by an installation that
 * built this, measured it, and offered it upstream; the ten scenarios that covered theirs
 * are the tests beside this file.
 *
 * ## The four fields, and why two are not enough
 *
 * A floor, a ceiling, a backlog at which to **grow**, and a lower backlog at which to
 * **shrink**. One threshold oscillates by construction: scaling up drops the backlog under
 * it, which scales down, which raises it again — every cycle, for ever, and the reconcile
 * interval becomes the frequency of the oscillation. The gap between the two is what makes
 * the pool settle.
 *
 * ## A cooldown, which is the fifth field and not optional
 *
 * A step, then wait before the next one. Without it the pool keeps stepping in one direction
 * while the backlog still describes the world **before** the last step landed, so it
 * overshoots — and then corrects, and overshoots the other way. A simulation in the tests
 * beside this file produced `2,3,4,5,6,7,8,8,7,6,5,4,3,2,2,2,2,3,4,4` from a load three
 * workers could serve: a limit cycle, at the reconcile interval, from thresholds that look
 * perfectly reasonable.
 *
 * The filing that contributed this policy said so — *"plus a cooldown, otherwise the
 * reconcile interval becomes the frequency of the oscillation"* — and it was left out of the
 * first version here. The simulation asked for it back, which is the whole reason a scaling
 * decision is worth simulating rather than reasoning about.
 *
 * ## What "settled" can mean, and what it cannot
 *
 * A pool is a whole number of workers and the load it serves usually is not. When the
 * arrival rate needs three-point-something workers, no integer pool holds the backlog still
 * — so the pool **must** move between three and four, for ever, and that is arithmetic
 * rather than a defect.
 *
 * So the guarantee is a **bound, not a fixed point**: the pool stays within one worker either
 * side of what the load needs — a span of two — and it walks 3,4,3,2,3,4… for ever. What the
 * cooldown buys is exactly that bound. Without it the same simulation ramps to the ceiling and
 * crashes to the floor, a span of **six**, at the reconcile interval. Both are in the tests:
 * the bound asserted, and the oscillation asserted *as* an oscillation, so that removing the
 * cooldown reddens something rather than leaving the reason in a comment.
 *
 * ## One step per cycle
 *
 * Never a jump to a computed target. **This is the whole load protection**, and it is why
 * there is no controller to tune: a policy that leaps has to be right about capacity in
 * advance, an incremental one only has to be right about the *direction*. Ten seconds a
 * step means one worker becomes eight in eighty seconds, and if the machine got busier in
 * the meantime the next step simply does not happen.
 *
 * ## Load gates growth, never shrinking
 *
 * Past a configured load per core nothing is added, however deep the queue. But **load alone
 * never sheds a worker**: the load may not be the queue's at all — somebody else's cron job,
 * a backup, a neighbour on the same host — and shedding would fail to fix that while making
 * the backlog worse. Shrinking is the backlog's decision alone.
 *
 * Getting that backwards produces a pool that abandons its work whenever anything else on
 * the machine is busy, which is the failure that looks most like the thing it was meant to
 * prevent.
 *
 * ## A backlog that cannot be read is no backlog
 *
 * `null` holds the pool at its floor. Guessing high spawns workers during exactly the
 * incident that broke the query, and a supervisor adding load to a database that has stopped
 * answering is a supervisor making an outage worse.
 *
 * ## And it is unsafe without the rest of the queue's guarantees
 *
 * Every shrink stops a worker. Until a stopped worker finished the task in hand rather than
 * abandoning it, an autoscaler on top was a machine for losing work in proportion to how
 * much it scaled — so `queue:reclaim` and the cooperative stop are prerequisites, not
 * companions. They are both in the framework; this is written on that footing.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class BurstPolicy
{
    /**
     * @param int        $floor        Workers to keep running at all times; at least 0.
     * @param int        $ceiling      Never exceed this, whatever the backlog says.
     * @param int        $growAbove    Grow when the backlog is above this.
     * @param int        $shrinkBelow  Shrink when the backlog is below this. Must be lower
     *                                 than $growAbove, or the pool oscillates — the
     *                                 constructor widens it rather than trusting it.
     * @param float|null $loadCeiling  Load per core past which nothing is added; null for no
     *                                 gate.
     * @param int        $cooldown     Cycles to wait after a change before making another.
     *                                 0 disables it, and the tests show what that costs.
     */
    public function __construct(
        private readonly int $floor = 1,
        private readonly int $ceiling = 4,
        private readonly int $growAbove = 1000,
        private readonly int $shrinkBelow = 200,
        private readonly ?float $loadCeiling = 0.75,
        private readonly int $cooldown = 3
    ) {
    }

    /**
     * Build a policy from an application's own configuration block.
     *
     * Every key optional, because a half-configured pool should run rather than throw: an
     * installation that names a ceiling and nothing else gets a sane floor and thresholds
     * around it.
     *
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config): self
    {
        $floor   = max(0, (int) ($config['floor'] ?? 1));
        $ceiling = max($floor, (int) ($config['ceiling'] ?? max(4, $floor)));

        $grow   = max(1, (int) ($config['grow_above'] ?? 1000));
        $shrink = (int) ($config['shrink_below'] ?? (int) floor($grow / 5));

        $loadCeiling = array_key_exists('load_ceiling', $config)
            ? ($config['load_ceiling'] === null ? null : (float) $config['load_ceiling'])
            : 0.75;

        $cooldown = max(0, (int) ($config['cooldown'] ?? 3));

        return new self($floor, $ceiling, $grow, $shrink, $loadCeiling, $cooldown);
    }

    /**
     * How many workers there should be, one step from where they are.
     *
     * @param int        $running     How many are running now.
     * @param int|null   $backlog     Pending items, or null when it could not be read.
     * @param float|null $loadPerCore Current load average divided by core count, or null
     *                                when unknown — which does **not** gate growth, because
     *                                a machine whose load cannot be read is not a machine
     *                                known to be busy.
     * @param int|null   $sinceChange Cycles since this pool last changed size; null when the
     *                                caller does not track it, which disables the cooldown.
     * @return int The target for this cycle: `$running`, `$running + 1` or `$running - 1`,
     *             clamped to the floor and the ceiling.
     */
    public function target(
        int $running,
        ?int $backlog,
        ?float $loadPerCore = null,
        ?int $sinceChange = null
    ): int {
        $running = max(0, $running);

        // Below the floor is not a scaling decision — it is a pool that is short, and the
        // floor is a promise. Filled in one step like everything else, so a floor raised from
        // 1 to 10 does not spawn nine processes into whatever is already happening.
        if ($running < $this->floor) {
            return $running + 1;
        }

        if ($running > $this->ceiling) {
            return $running - 1;
        }

        /*
         * The cooldown, and it is deliberately **after** the floor and ceiling clamps.
         *
         * Those two are promises rather than scaling decisions — a pool below its floor is
         * short, and waiting three cycles to fill it would be waiting three cycles to keep a
         * promise. The cooldown damps the *reaction to the backlog*, which is the thing that
         * overshoots, because the backlog still describes the world before the last step
         * landed.
         */
        if ($sinceChange !== null && $this->cooldown > 0 && $sinceChange < $this->cooldown) {
            return $running;
        }

        /*
         * A backlog that could not be read holds the pool where it is, at or above the floor.
         *
         * Not "grow, to be safe": the query most likely failed because the database is in
         * trouble, and adding workers to it is making an outage worse. Not "shrink" either —
         * that would drain the pool during a blip.
         */
        if ($backlog === null) {
            return max($this->floor, min($running, $this->ceiling));
        }

        if ($backlog > $this->growAbove) {
            // The load gate: past the ceiling, nothing is added however deep the queue.
            if ($this->loadCeiling !== null
                && $loadPerCore !== null
                && $loadPerCore >= $this->loadCeiling
            ) {
                return $running;
            }

            return min($this->ceiling, $running + 1);
        }

        if ($backlog < $this->shrinkBelow) {
            /*
             * Load is deliberately **not** consulted here. It may not be the queue's, and
             * shedding a worker would fail to fix that while making the backlog worse.
             * Shrinking is the backlog's decision alone.
             */
            return max($this->floor, $running - 1);
        }

        // Between the two thresholds: the gap that stops the oscillation.
        return $running;
    }

    /**
     * Why the last answer was what it was, in words, for a log line.
     *
     * A scaling controller needs its decision and the resulting process count **on the same
     * line**, or a supervisor that undoes its own decisions is indistinguishable from one
     * that never makes them. An installation investigated "burst is not working" three times
     * against the thresholds and the load gate — each time correctly confirming the policy —
     * while something else killed two workers every cycle.
     */
    public function explain(
        int $running,
        ?int $backlog,
        ?float $loadPerCore = null,
        ?int $sinceChange = null
    ): string {
        $target = $this->target($running, $backlog, $loadPerCore, $sinceChange);
        $reason = $this->reason($running, $backlog, $loadPerCore, $target, $sinceChange);

        return sprintf(
            'running=%d target=%d backlog=%s load=%s floor=%d ceiling=%d grow>%d shrink<%d — %s',
            $running,
            $target,
            $backlog === null ? 'unknown' : (string) $backlog,
            $loadPerCore === null ? 'unknown' : sprintf('%.2f', $loadPerCore),
            $this->floor,
            $this->ceiling,
            $this->growAbove,
            $this->shrinkBelow,
            $reason
        );
    }

    private function reason(
        int $running,
        ?int $backlog,
        ?float $loadPerCore,
        int $target,
        ?int $sinceChange = null
    ): string {
        if ($running < $this->floor) {
            return 'below the floor';
        }

        if ($running > $this->ceiling) {
            return 'above the ceiling';
        }

        if ($sinceChange !== null && $this->cooldown > 0 && $sinceChange < $this->cooldown) {
            return sprintf('cooling down (%d of %d cycles)', $sinceChange, $this->cooldown);
        }

        if ($backlog === null) {
            return 'backlog unreadable, holding';
        }

        if ($backlog > $this->growAbove) {
            if ($target === $running) {
                return $this->loadCeiling !== null && $loadPerCore !== null
                    && $loadPerCore >= $this->loadCeiling
                    ? sprintf('backlog wants a worker, load gate closed at %.2f', $this->loadCeiling)
                    : 'at the ceiling';
            }

            return 'backlog above the grow threshold';
        }

        if ($backlog < $this->shrinkBelow) {
            return $target === $running ? 'at the floor' : 'backlog below the shrink threshold';
        }

        return 'between the thresholds';
    }
}
