<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\BurstPolicy;

/**
 * The scaling decision, tested without a queue, a database or a busy machine.
 *
 * That is the whole reason the policy is pure — floor, ceiling, running, backlog,
 * thresholds and load in, a number out. A scaling decision that can only be exercised by
 * arranging a real overloaded system is one nobody dares change, and the installation that
 * built this said so: *"Keep the policy pure … so every branch is testable without a queue,
 * a database or a busy machine. Ten scenarios covered ours."*
 *
 * Each test below is one of the traps rather than one of the branches, because the branches
 * are obvious and the traps are what cost time.
 */
#[CoversClass(BurstPolicy::class)]
class BurstPolicyTest extends TestCase
{
    private function policy(
        int $floor = 1,
        int $ceiling = 8,
        int $grow = 1000,
        int $shrink = 200,
        ?float $loadCeiling = 0.75
    ): BurstPolicy {
        return new BurstPolicy($floor, $ceiling, $grow, $shrink, $loadCeiling);
    }

    /**
     * One step per cycle, never a jump to a computed target.
     *
     * **The whole load protection**, and the reason there is no controller to tune: a policy
     * that leaps has to be right about capacity in advance; an incremental one only has to be
     * right about the direction, and if the machine got busier the next step simply does not
     * happen. A backlog a hundred times the threshold still buys one worker.
     */
    public function testGrowthIsOneStepHoweverDeepTheBacklog(): void
    {
        // Act + Assert
        $this->assertSame(2, $this->policy()->target(1, 100_000, 0.1));
        $this->assertSame(3, $this->policy()->target(2, 100_000, 0.1));
    }

    /**
     * Load gates growth.
     *
     * Past the ceiling nothing is added, however deep the queue — because the next worker
     * would be contending for a machine that is already the limit.
     */
    public function testLoadStopsGrowth(): void
    {
        // Act + Assert
        $this->assertSame(1, $this->policy()->target(1, 100_000, 0.80), 'grew past the load ceiling');
        $this->assertSame(2, $this->policy()->target(1, 100_000, 0.74));
    }

    /**
     * And load **never** sheds a worker — which is the half that is easy to get backwards.
     *
     * The load may not be the queue's at all: somebody else's cron job, a backup, a neighbour
     * on the same host. Shedding would fail to fix that while making the backlog worse, so
     * shrinking is the backlog's decision alone.
     *
     * Getting this wrong produces a pool that abandons its work whenever anything else on the
     * machine is busy — the failure that looks most like the thing it was meant to prevent.
     */
    public function testLoadNeverShrinksThePool(): void
    {
        // Arrange — a machine well past its load ceiling, and a backlog that wants workers
        $policy = $this->policy();

        // Act + Assert — held, not shed
        $this->assertSame(4, $policy->target(4, 100_000, 5.0));

        /*
         * And the assertion that actually distinguishes the two designs: a backlog **inside
         * the gap**, with the machine on fire.
         *
         * The line above does not. With a backlog above the grow threshold the decision is
         * made in the grow branch, the load gate closes it, and "held" is the answer whether
         * or not load also sheds — so a policy that shed on load passed it. Found by reverting
         * the condition and watching nothing go red.
         */
        $this->assertSame(4, $policy->target(4, 500, 5.0), 'load shed a worker on its own');

        // and a *small* backlog does shrink, at any load
        $this->assertSame(3, $policy->target(4, 10, 5.0));
    }

    /**
     * Two thresholds with a gap, because one oscillates by construction.
     *
     * With a single threshold, scaling up drops the backlog under it, which scales down,
     * which raises it again — every cycle, for ever, and the reconcile interval becomes the
     * frequency of the oscillation. A backlog inside the gap holds the pool where it is, and
     * that is what makes it settle.
     */
    public function testABacklogInsideTheGapHoldsThePool(): void
    {
        // Arrange — between shrink (200) and grow (1000)
        $policy = $this->policy();

        // Assert
        $this->assertSame(4, $policy->target(4, 500, 0.1));
        $this->assertSame(4, $policy->target(4, 201, 0.1));
        $this->assertSame(4, $policy->target(4, 1000, 0.1), 'the grow threshold is exclusive');
    }

    /**
     * A backlog that could not be read holds the pool at its floor.
     *
     * **Not "grow, to be safe."** The query most likely failed because the database is in
     * trouble, and a supervisor adding workers to a database that has stopped answering is a
     * supervisor making an outage worse. Not "shrink" either, which would drain the pool over
     * a blip.
     */
    public function testAnUnreadableBacklogHoldsAtTheFloor(): void
    {
        // Arrange
        $policy = $this->policy(2, 8);

        // Assert
        $this->assertSame(5, $policy->target(5, null, 0.1), 'an unknown backlog changed the pool');

        // One step towards the floor, not a jump to it — the same rule as growth, and the
        // first version of this test asserted 2 and contradicted the rest of the class.
        $this->assertSame(1, $policy->target(0, null, 0.1), 'the floor was not approached');
    }

    /**
     * An unknown load does not gate growth.
     *
     * A machine whose load cannot be read is not a machine known to be busy, and treating
     * *unknown* as *over the ceiling* would freeze every pool on a platform without a load
     * average. The two unknowns are deliberately asymmetric: an unreadable **backlog** holds,
     * an unreadable **load** does not.
     */
    public function testAnUnknownLoadDoesNotGateGrowth(): void
    {
        // Assert
        $this->assertSame(2, $this->policy()->target(1, 100_000, null));
    }

    /**
     * The floor and the ceiling are honoured, and reached one step at a time.
     *
     * A floor raised from 1 to 10 in configuration must not spawn nine processes into
     * whatever is already happening on the machine — the same reason growth is incremental.
     */
    public function testTheFloorAndCeilingAreApproachedOneStepAtATime(): void
    {
        // Arrange
        $policy = $this->policy(4, 6);

        // Assert — short of the floor, one at a time
        $this->assertSame(1, $policy->target(0, 0, 0.1));
        $this->assertSame(2, $policy->target(1, 0, 0.1));

        // over the ceiling — a ceiling lowered in configuration — one at a time down
        $this->assertSame(9, $policy->target(10, 100_000, 0.1));

        // and neither is crossed
        $this->assertSame(4, $policy->target(4, 0, 0.1), 'shrank below the floor');
        $this->assertSame(6, $policy->target(6, 100_000, 0.1), 'grew past the ceiling');
    }

    /**
     * The pool stays within one worker of what the load needs.
     *
     * **The guarantee is a bound, not a fixed point**, and the first version of this test
     * asked for the wrong thing. A pool is a whole number of workers and the load it serves
     * usually is not: at 900 arrivals a cycle and 300 drained per worker, the load needs
     * exactly three — and when it needs three-point-something, no integer pool holds the
     * backlog still. The pool *must* move between three and four for ever. That is arithmetic.
     *
     * What the cooldown buys is precisely that bound: a span of **two** — one worker either
     * side of the three the load needs — instead of ramping to the ceiling and crashing to the
     * floor, which is a span of six. {@see testWithoutTheCooldownItOscillates()} is
     * the same simulation with it switched off, kept as a failing-shape assertion so the
     * reason cannot quietly stop being true.
     */
    public function testThePoolStaysWithinOneWorkerOfWhatTheLoadNeeds(): void
    {
        // Arrange — each worker drains 300 items per cycle, 900 arrive: three workers suffice
        $policy  = new BurstPolicy(1, 8, 1000, 200, 0.75, 3);
        $running = 1;
        $backlog = 5000;
        $since   = PHP_INT_MAX;
        $history = array();

        // Act
        // Long enough for the ramp from 5,000 to damp out; the steady state is reached by
        // about cycle 60 and does not change after it.
        for ($cycle = 0; $cycle < 120; $cycle++) {
            $target = $policy->target($running, $backlog, 0.2, $since);
            $since  = $target === $running ? min(999, $since + 1) : 0;
            $running = $target;
            $backlog = max(0, $backlog - ($running * 300) + 900);
            $history[] = $running;
        }

        /*
         * Assert — past the ramp, the pool stays within **one worker either side** of the
         * three the load needs. A span of two, not a fixed point: the equilibrium is exactly
         * three and an integer pool around it cannot hold still, so it walks 3,4,3,2,3,4…
         *
         * Measured steady state: `3,3,3,3,4,4,4,4,3,3,3,3,2,2,2,2,3,3,3,3`.
         */
        $tail = array_slice($history, -20);

        $this->assertLessThanOrEqual(
            2,
            max($tail) - min($tail),
            'the pool swung by more than one worker either side: ' . implode(',', $history)
        );

        // and it is around the three the load needs, not parked at the floor or the ceiling
        $this->assertGreaterThanOrEqual(2, min($tail));
        $this->assertLessThanOrEqual(4, max($tail), 'the pool settled above what the load needs');
    }

    /**
     * A configuration block missing everything still produces a usable policy.
     *
     * A half-configured pool should run rather than throw, and `shrink_below` defaults *below*
     * `grow_above` rather than to a fixed number — a gap that is derived cannot be configured
     * shut by accident.
     */
    public function testAPartialConfigurationIsUsable(): void
    {
        // Act
        $policy = BurstPolicy::fromConfig(array('ceiling' => 6));

        // Assert — grows, and stops at the configured ceiling
        $this->assertSame(2, $policy->target(1, 100_000, 0.1));
        $this->assertSame(6, $policy->target(6, 100_000, 0.1));

        // and an empty block is still a policy
        $this->assertSame(1, BurstPolicy::fromConfig(array())->target(1, 0, 0.1));
    }

    /**
     * A ceiling below the floor is widened rather than trusted.
     *
     * `['floor' => 4, 'ceiling' => 2]` is a typo, and honouring it literally makes the two
     * clamps fight: one step up because of the floor, one step down because of the ceiling,
     * every cycle for ever. That is the oscillation the two thresholds exist to prevent,
     * arriving from the configuration instead.
     */
    public function testACeilingBelowTheFloorIsWidened(): void
    {
        // Act
        $policy = BurstPolicy::fromConfig(array('floor' => 4, 'ceiling' => 2));

        // Assert — settled at 4, not flapping between 3 and 5
        $this->assertSame(4, $policy->target(4, 0, 0.1));
        $this->assertSame(4, $policy->target(4, 100_000, 0.1));
    }

    /**
     * No load ceiling means no gate.
     *
     * For a dedicated queue host where the load *is* the queue's and holding back is just
     * leaving capacity unused.
     */
    public function testNoLoadCeilingMeansNoGate(): void
    {
        // Act
        $policy = BurstPolicy::fromConfig(array('load_ceiling' => null, 'ceiling' => 8));

        // Assert
        $this->assertSame(2, $policy->target(1, 100_000, 99.0));
    }

    /**
     * The decision explains itself, on one line, with the numbers that produced it.
     *
     * A scaling controller needs its decision and the resulting process count **side by
     * side**, or a supervisor that undoes its own decisions is indistinguishable from one
     * that never makes them. An installation investigated "burst is not working" three times
     * against the thresholds and the load gate — each time correctly confirming the policy —
     * while a prefix-matching deduplicator killed two workers every cycle.
     */
    public function testTheDecisionExplainsItself(): void
    {
        // Act
        $gated = $this->policy()->explain(4, 100_000, 0.9);

        // Assert — the inputs and the outcome, in one string
        $this->assertStringContainsString('running=4', $gated);
        $this->assertStringContainsString('target=4', $gated);
        $this->assertStringContainsString('backlog=100000', $gated);
        $this->assertStringContainsString('load gate closed', $gated);

        // and the other reasons are named rather than left to be inferred
        $this->assertStringContainsString('backlog unreadable', $this->policy()->explain(4, null));
        $this->assertStringContainsString('between the thresholds', $this->policy()->explain(4, 500, 0.1));
        $this->assertStringContainsString('shrink threshold', $this->policy()->explain(4, 10, 0.1));
        $this->assertStringContainsString('grow threshold', $this->policy()->explain(4, 5000, 0.1));
        $this->assertStringContainsString('below the floor', $this->policy(4)->explain(1, 0, 0.1));
        $this->assertStringContainsString('above the ceiling', $this->policy(1, 2)->explain(9, 0, 0.1));
        $this->assertStringContainsString('at the ceiling', $this->policy(1, 4)->explain(4, 5000, 0.1));
        $this->assertStringContainsString('at the floor', $this->policy(2, 4)->explain(2, 0, 0.1));
    }

    /**
     * Without the cooldown, the same load produces a limit cycle.
     *
     * **The finding that put the cooldown in.** The first version of this policy had the four
     * fields the filing named and not the fifth, and this simulation — a load three workers
     * can serve, thresholds that look perfectly reasonable — produced
     * `2,3,4,5,6,7,8,8,7,6,5,4,3,2,2,2,2,3,4,4`: the pool ramping to its ceiling, crashing to
     * its floor, and doing it again, at the reconcile interval.
     *
     * The cause is not the thresholds. It is that the pool keeps stepping in one direction
     * while the backlog still describes the world **before** the last step landed. Asserted as
     * a failure so that removing the cooldown reddens something, rather than leaving the
     * reason in a comment.
     */
    public function testWithoutTheCooldownItOscillates(): void
    {
        // Arrange — identical to the settling test, cooldown disabled
        $policy  = new BurstPolicy(1, 8, 1000, 200, 0.75, 0);
        $running = 1;
        $backlog = 5000;
        $history = array();

        // Act
        for ($cycle = 0; $cycle < 20; $cycle++) {
            $running = $policy->target($running, $backlog, 0.2, 0);
            $backlog = max(0, $backlog - ($running * 300) + 900);
            $history[] = $running;
        }

        // Assert — it swings far more than the one worker the cooldown bounds it to
        $this->assertGreaterThan(
            1,
            max($history) - min($history),
            'the fixture no longer demonstrates the oscillation, so it proves nothing: '
            . implode(',', $history)
        );

        // Measured: 2,3,4,5,6,7,8,8,7,6,5,4,3,2,2,2,2,3,4,4 — the ceiling and then the floor
        $this->assertGreaterThanOrEqual(
            4,
            max($history) - min($history),
            'the oscillation is smaller than it was, so this is no longer the same finding'
        );
    }

    /**
     * A pool inside its cooldown holds, and says so.
     *
     * The mechanism on its own, without a simulation: a backlog deep enough to grow does not
     * grow while the last change is still settling.
     */
    public function testAPoolInsideItsCooldownHolds(): void
    {
        // Arrange
        $policy = new BurstPolicy(1, 8, 1000, 200, 0.75, 3);

        // Assert — held for the first three cycles, then free
        $this->assertSame(4, $policy->target(4, 100_000, 0.1, 0));
        $this->assertSame(4, $policy->target(4, 100_000, 0.1, 2));
        $this->assertSame(5, $policy->target(4, 100_000, 0.1, 3));

        $this->assertStringContainsString(
            'cooling down (1 of 3 cycles)',
            $policy->explain(4, 100_000, 0.1, 1)
        );
    }

    /**
     * The cooldown does not delay the floor or the ceiling.
     *
     * Those are promises rather than scaling decisions: a pool below its floor is *short*, and
     * waiting three cycles to fill it is waiting three cycles to keep a promise. Which is why
     * the cooldown sits after both clamps and not before them.
     */
    public function testTheCooldownDoesNotDelayTheFloorOrTheCeiling(): void
    {
        // Arrange
        $policy = new BurstPolicy(4, 6, 1000, 200, 0.75, 5);

        // Assert — still filling towards the floor, and still coming down from over the ceiling
        $this->assertSame(2, $policy->target(1, 0, 0.1, 0));
        $this->assertSame(9, $policy->target(10, 0, 0.1, 0));
    }

    /**
     * A caller that does not track cycles gets no cooldown, and that is a real choice.
     *
     * `null` rather than a default of zero: a supervisor that has not been taught to remember
     * when it last changed the pool would otherwise be silently in permanent cooldown, or
     * silently without one, depending on which way the default fell. Asking for the number
     * makes the omission visible.
     */
    public function testACallerThatDoesNotTrackCyclesGetsNoCooldown(): void
    {
        // Arrange
        $policy = new BurstPolicy(1, 8, 1000, 200, 0.75, 3);

        // Assert
        $this->assertSame(5, $policy->target(4, 100_000, 0.1), 'the cooldown fired without a count');
    }
}
