<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Companion\Calendar;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

/**
 * The companion's calendar — appointments and tasks on the person's clock — on MySQL and
 * PostgreSQL.
 *
 * What holds: a time given on the person's clock comes back on it, whatever zone the database
 * keeps; an appointment that overlaps another is not made, and the answer names it and offers the
 * nearest free starts before and after; a move keeps the length and checks again, leaving itself
 * out; tasks list soonest due first; and nobody reads or changes another person's.
 */
#[CoversClass(Calendar::class)]
class CompanionCalendarTest extends LlmTestCase
{
    use ChatDatabase;

    protected function tearDown(): void
    {
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** A calendar on Athens' clock, for a person of its own. */
    private function calendar(string $type, string $host, int $port, int $userId = 41): Calendar
    {
        $this->connectDatabase($type, $host, $port);

        return new Calendar(new Scope($userId, null), 'Europe/Athens');
    }

    /** Run one of the calendar's tools as the model would, and decode what it answered. */
    private static function call(Calendar $calendar, string $tool, array $arguments): mixed
    {
        $said = ($calendar->tools()[$tool]['run'])($arguments);

        return json_decode($said, true) ?? $said;
    }

    /**
     * Made at 16:00 Athens time, an appointment is 16:00 when read back, an hour long by default,
     * and is stored as the moment (13:00 UTC in October's summer time).
     */
    #[DataProvider('databases')]
    public function testAnAppointmentOnThePersonsClock(string $type, string $host, int $port): void
    {
        // Arrange
        $calendar = $this->calendar($type, $host, $port);

        // Act
        $made   = self::call($calendar, 'calendar_create_event', ['title' => 'Giorgos, project', 'start' => '2026-10-20 16:00']);
        $listed = self::call($calendar, 'calendar_list_events', ['from' => '2026-10-20', 'to' => '2026-10-20']);
        $feed   = $calendar->feed('2026-10-19T00:00:00Z', '2026-10-21T00:00:00Z');

        // Assert
        $this->assertSame(['2026-10-20 16:00', '2026-10-20 17:00'], [$made['created']['start'], $made['created']['end']]);
        $this->assertCount(1, $listed);
        $this->assertSame('Giorgos, project', $listed[0]['title']);
        $this->assertSame('2026-10-20T16:00:00+03:00', $feed[0]['start'], 'the feed is ISO with the person\'s offset');
        $this->assertSame(strtotime('2026-10-20 13:00:00 UTC'), strtotime($feed[0]['start']), 'the same moment: 13:00 UTC');
        $this->assertFalse($feed[0]['allDay']);
        $this->assertSame([], self::call($calendar, 'calendar_list_events', ['from' => '2026-10-21', 'to' => 'tomorrow']));
    }

    /**
     * An overlap is refused with what is there and the nearest free starts before and after —
     * the walkthrough's "the accountant is at 16:00: 14:30 or 17:00?" — and nothing is made.
     */
    #[DataProvider('databases')]
    public function testAnOverlapIsAConflictWithFreeSlots(string $type, string $host, int $port): void
    {
        // Arrange
        $calendar = $this->calendar($type, $host, $port);
        $calendar->create('Accountant', '2026-10-20 15:30', '2026-10-20 17:00');
        $calendar->create('Lunch', '2026-10-20 13:00', '2026-10-20 14:30');
        $calendar->create('Holiday', '2026-10-20', null, true);

        // Act
        $refused = self::call($calendar, 'calendar_create_event', ['title' => 'Giorgos', 'start' => '2026-10-20 16:00']);

        // Assert
        $this->assertSame('conflict', $refused['error']);
        $this->assertSame('Accountant', $refused['existing']);
        $this->assertSame(['14:30', '17:00'], $refused['free_slots'], 'an hour free before 15:30, and right after');
        $this->assertCount(3, $calendar->events('2026-10-20', '2026-10-20'), 'nothing was made; an all-day event is no conflict');
    }

    /**
     * A move keeps the length and is checked again — not against itself; a rename leaves the
     * time; a cancelled appointment is gone. Bad input is refused with a sentence.
     */
    #[DataProvider('databases')]
    public function testChangingAndCancelling(string $type, string $host, int $port): void
    {
        // Arrange
        $calendar = $this->calendar($type, $host, $port);
        $id       = $calendar->create('Dentist', '2026-10-21 10:00', '2026-10-21 10:45')['created']['id'];
        $calendar->create('Call', '2026-10-21 12:00');

        // Act
        $later   = self::call($calendar, 'calendar_update_event', ['id' => $id, 'start' => '2026-10-21 10:30']);
        $clash   = self::call($calendar, 'calendar_update_event', ['id' => $id, 'start' => '2026-10-21 11:30']);
        $renamed = self::call($calendar, 'calendar_update_event', ['id' => $id, 'title' => 'Dentist, check-up']);
        $moved   = $calendar->move(['id' => $id, 'start' => '2026-10-21T15:00:00+03:00', 'end' => '2026-10-21T16:00:00+03:00']);
        $gone    = self::call($calendar, 'calendar_delete_event', ['id' => $id]);

        // Assert
        $this->assertSame(['2026-10-21 10:30', '2026-10-21 11:15'], [$later['updated']['start'], $later['updated']['end']], 'the same 45 minutes');
        $this->assertSame(['conflict', 'Call'], [$clash['error'], $clash['existing']]);
        $this->assertSame(['Dentist, check-up', '2026-10-21 10:30'], [$renamed['updated']['title'], $renamed['updated']['start']]);
        $this->assertSame(['2026-10-21 15:00', '2026-10-21 16:00'], [$moved['updated']['start'], $moved['updated']['end']], 'a drag on the page');
        $this->assertSame('Cancelled.', $gone);
        $this->assertSame('Error: there is no appointment ' . $id . '.', self::call($calendar, 'calendar_delete_event', ['id' => $id]));
        $this->assertSame(['error' => 'There is no appointment 999999.'], $calendar->update(999999, []));
        $this->assertSame(['error' => 'An appointment needs a title and a start, as Y-m-d H:i.'], $calendar->create('X', 'four o\'clock'));
        $this->assertSame(['error' => 'It ends before it starts.'], $calendar->create('X', '2026-10-22 10:00', '2026-10-22 09:00'));
        $this->assertSame([], $calendar->feed('nonsense', 'more'));
    }

    /**
     * Tasks: made with or without a due time, listed soonest first and undated last, marked done
     * and then listed among the done ones; a bad due time is refused.
     */
    #[DataProvider('databases')]
    public function testTasks(string $type, string $host, int $port): void
    {
        // Arrange
        $calendar = $this->calendar($type, $host, $port);

        // Act
        $someday = self::call($calendar, 'task_create', ['title' => 'Read the book']);
        self::call($calendar, 'task_create', ['title' => 'Send invoice', 'due' => '2026-10-25 12:00']);
        self::call($calendar, 'task_create', ['title' => 'Call the bank', 'due' => '2026-10-23 09:00']);
        $open = self::call($calendar, 'task_list', []);
        $done = self::call($calendar, 'task_update', ['id' => $someday['created']['id'], 'done' => true, 'due' => '2026-10-30 18:00']);

        // Assert
        $this->assertSame(['Call the bank', 'Send invoice', 'Read the book'], array_column($open, 'title'));
        $this->assertTrue($done['updated']['done']);
        $this->assertSame('2026-10-30 18:00', $done['updated']['due']);
        $this->assertSame(['Read the book'], array_column(self::call($calendar, 'task_list', ['done' => true]), 'title'));
        $this->assertSame(['error' => 'A task needs a title; a due time is Y-m-d H:i.'], $calendar->addTask('X', 'soon'));
        $this->assertSame(['error' => 'A due time is Y-m-d H:i.'], $calendar->updateTask($someday['created']['id'], ['due' => 'soon']));
        $this->assertSame(['error' => 'There is no task 999999.'], $calendar->updateTask(999999, []));
        $this->assertSame(['error' => 'There is no appointment ' . $someday['created']['id'] . '.'], $calendar->update($someday['created']['id'], []), 'a task is not an appointment');
    }

    /**
     * Another person's calendar is not this one's, and the reminder routine finds what starts
     * soon over everybody, once: marked, it is not found again; moved, it is due again.
     */
    #[DataProvider('databases')]
    public function testScopeBusyAndReminders(string $type, string $host, int $port): void
    {
        // Arrange
        $mine   = $this->calendar($type, $host, $port);
        $theirs = new Calendar(new Scope(42, null), 'Europe/Athens');
        $now    = time();
        $soon   = (new \DateTimeImmutable('@' . ($now + 20 * 60)))->setTimezone(new \DateTimeZone('Europe/Athens'))->format('Y-m-d H:i');
        $id     = $mine->create('Meeting', $soon)['created']['id'];
        $theirs->create('Theirs', (new \DateTimeImmutable('@' . ($now + 3 * 3600)))->setTimezone(new \DateTimeZone('Europe/Athens'))->format('Y-m-d H:i'));

        // Act
        $due = Calendar::startingSoon($now, 30);
        Calendar::alerted($id);
        $after = Calendar::startingSoon($now, 30);
        $mine->update($id, ['start' => (new \DateTimeImmutable('@' . ($now + 25 * 60)))->setTimezone(new \DateTimeZone('Europe/Athens'))->format('Y-m-d H:i')]);
        $again = Calendar::startingSoon($now, 30);

        // Assert
        $this->assertSame([$id], array_map(static fn (array $r): int => (int) $r['item_id'], $due));
        $this->assertSame([], $after);
        $this->assertCount(1, $again, 'a new time is a new reminder');
        $this->assertTrue($mine->busyAt($now + 30 * 60));
        $this->assertFalse($mine->busyAt($now + 3 * 3600), 'their appointment is not this person\'s');
        $this->assertFalse($theirs->delete($id));
        $this->assertTrue($mine->tools()['calendar_create_event']['writes']);
        $this->assertTrue($mine->tools()['task_list']['parallel']);
    }
}
