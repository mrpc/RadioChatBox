<?php

namespace RadioChatBox\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use RadioChatBox\Database;
use RadioChatBox\Services\Scheduler;
use RadioChatBox\Services\SettingsService;

/**
 * Covers the periodic tasks the worker runs in place of crontab entries.
 *
 * The point of moving them is that the worker already guarantees a single instance and
 * reports its health; the risk is a task that runs too often, not at all, or takes the
 * worker down with it. All three are pinned here.
 */
class SchedulerTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Database::getPDO();
        $this->pdo->exec("DELETE FROM scheduled_tasks WHERE task LIKE 'test_%'");
    }

    protected function tearDown(): void
    {
        $this->pdo->exec("DELETE FROM scheduled_tasks WHERE task LIKE 'test_%'");
    }

    // ------------------------------------------------------------------
    // The real task table
    // ------------------------------------------------------------------

    public function testEveryTaskIsRunnableAndDescribed(): void
    {
        $scheduler = new Scheduler();

        foreach (Scheduler::TASKS as $name => $meta) {
            $this->assertGreaterThan(0, $meta['every'], "{$name} needs an interval");
            $this->assertNotSame('', trim((string) $meta['description']), "{$name} needs a description");

            // A name with no runner would fail only at 3am in production.
            $this->assertNotSame(
                'failed',
                $this->reflectRunnerExists($scheduler, $name) ? 'ok' : 'failed',
                "{$name} has no runner"
            );
        }
    }

    /**
     * The current track has to be caught while it is playing: bundled with the
     * five-minute stats snapshot, a three-minute song was missed entirely.
     */
    public function testTheStreamIsPolledFarMoreOftenThanStatsAreAggregated(): void
    {
        $this->assertLessThanOrEqual(60, Scheduler::TASKS['track_poll']['every']);
        $this->assertGreaterThanOrEqual(
            Scheduler::TASKS['track_poll']['every'] * 2,
            Scheduler::TASKS['stats_snapshot']['every']
        );
    }

    public function testThePollIntervalCanBeTunedFromSettings(): void
    {
        $settings = new class extends SettingsService {
            public function get(string $key, mixed $default = null): mixed
            {
                return $key === 'track_poll_seconds' ? '45' : $default;
            }
        };

        $this->assertSame(45, (new Scheduler($settings))->tasks()['track_poll']['every']);
    }

    public function testAnAbsurdPollIntervalIsClamped(): void
    {
        foreach (['1' => 10, '999999' => 3600] as $configured => $expected) {
            $settings = new class ((string) $configured) extends SettingsService {
                public function __construct(private string $value)
                {
                    parent::__construct();
                }

                public function get(string $key, mixed $default = null): mixed
                {
                    return $key === 'track_poll_seconds' ? $this->value : $default;
                }
            };

            // Hammering the stream every second, or polling once an hour, are both
            // ways to make this useless.
            $this->assertSame($expected, (new Scheduler($settings))->tasks()['track_poll']['every']);
        }
    }

    // ------------------------------------------------------------------
    // Due-ness
    // ------------------------------------------------------------------

    public function testATaskThatNeverRanIsDue(): void
    {
        $scheduler = $this->schedulerWith(['test_thing' => static fn () => null]);

        $this->assertSame(['test_thing'], $scheduler->dueTasks());
    }

    public function testATaskIsNotDueAgainWithinItsInterval(): void
    {
        $scheduler = $this->schedulerWith(['test_thing' => static fn () => null]);
        $scheduler->run('test_thing');

        // This is what makes running the crontab and the worker at once harmless.
        $this->assertSame([], $scheduler->dueTasks());
    }

    public function testATaskIsDueAgainAfterItsInterval(): void
    {
        $scheduler = $this->schedulerWith(['test_thing' => static fn () => null]);
        $scheduler->run('test_thing');

        $this->backdate('test_thing', 3600);

        $this->assertSame(['test_thing'], $scheduler->dueTasks());
    }

    // ------------------------------------------------------------------
    // Running
    // ------------------------------------------------------------------

    /**
     * Running the same task twice must accumulate the run counter rather than
     * reset it — the ON CONFLICT ... DO UPDATE SET runs = runs + 1 path. This
     * pins the counter-increment behaviour after the move to the QueryBuilder's
     * associative upsert(), which the plain EXCLUDED-only form cannot express.
     */
    public function testRunCounterAccumulatesAcrossRuns(): void
    {
        $scheduler = $this->schedulerWith(['test_thing' => static fn () => null]);

        $scheduler->run('test_thing');
        // The task is not due again within its interval, so backdate it to force
        // a second recorded run through the ON CONFLICT branch.
        $this->backdate('test_thing', 3600);
        $scheduler->run('test_thing');

        $row = $scheduler->state()['test_thing'];
        $this->assertSame(2, (int) $row['runs'], 'runs must increment, not reset');
        $this->assertSame(0, (int) $row['failures']);
    }

    /**
     * Failures accumulate across runs too (failures = failures + EXCLUDED.failures),
     * and a later success must not clear the historical failure count.
     */
    public function testFailureCounterAccumulatesAndSurvivesLaterSuccess(): void
    {
        $flaky = true;
        $scheduler = $this->schedulerWith(['test_flaky' => static function () use (&$flaky): void {
            if ($flaky) {
                throw new \RuntimeException('boom');
            }
        }]);

        // The scheduler logs each failure to the "radiochatbox" channel (file-only
        // under test — see phpunit.xml), so nothing surfaces as process output.
        $scheduler->run('test_flaky');            // failure → failures = 1
        $this->backdate('test_flaky', 3600);
        $scheduler->run('test_flaky');            // failure → failures = 2
        $this->backdate('test_flaky', 3600);
        $flaky = false;
        $scheduler->run('test_flaky');            // success → failures unchanged

        $row = $scheduler->state()['test_flaky'];
        $this->assertSame(3, (int) $row['runs']);
        $this->assertSame(2, (int) $row['failures'], 'past failures must persist');
        $this->assertSame('ok', $row['last_status']);
    }

    public function testARunIsRecordedWithItsOutcome(): void
    {
        $ran = 0;
        $scheduler = $this->schedulerWith(['test_thing' => function () use (&$ran): void {
            $ran++;
        }]);

        $result = $scheduler->run('test_thing');

        $this->assertSame(1, $ran);
        $this->assertSame('ok', $result['status']);
        $this->assertNull($result['error']);

        $row = $scheduler->state()['test_thing'];
        $this->assertSame('ok', $row['last_status']);
        $this->assertSame(1, (int) $row['runs']);
        $this->assertSame(0, (int) $row['failures']);
    }

    /**
     * One broken task must not take the worker - or the other tasks - down with it.
     */
    public function testAFailingTaskIsRecordedAndDoesNotThrow(): void
    {
        $secondRan = false;
        $scheduler = $this->schedulerWith([
            'test_broken' => static function (): void {
                throw new \RuntimeException('the stream is down');
            },
            'test_fine' => function () use (&$secondRan): void {
                $secondRan = true;
            },
        ]);


        $results = $scheduler->runDue(null, 5);

        $this->assertTrue($secondRan, 'a failure must not stop the tasks after it');
        $this->assertSame('failed', $results[0]['status']);
        $this->assertStringContainsString('the stream is down', (string) $results[0]['error']);

        $row = $scheduler->state()['test_broken'];
        $this->assertSame('failed', $row['last_status']);
        $this->assertSame(1, (int) $row['failures']);
        $this->assertStringContainsString('the stream is down', (string) $row['last_error']);

        // And it is retried on the next interval rather than being abandoned.
        $this->backdate('test_broken', 3600);
        $this->assertContains('test_broken', $scheduler->dueTasks());
    }

    public function testAnUnknownTaskFailsLoudlyRatherThanSilently(): void
    {

        $result = (new Scheduler())->run('no_such_task');

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('Unknown scheduled task', (string) $result['error']);
    }

    public function testOnlyAFewTasksRunPerTick(): void
    {
        $ran = [];
        $runners = [];
        foreach (['test_a', 'test_b', 'test_c', 'test_d'] as $name) {
            $runners[$name] = function () use (&$ran, $name): void {
                $ran[] = $name;
            };
        }

        $results = $this->schedulerWith($runners)->runDue(null, 2);

        // A backlog must not stall reply delivery: the worker returns on its next tick.
        $this->assertCount(2, $results);
        $this->assertSame(['test_a', 'test_b'], $ran);
    }

    public function testTheHeartbeatIsCalledBetweenTasks(): void
    {
        $beats = 0;
        $scheduler = $this->schedulerWith([
            'test_a' => static fn () => null,
            'test_b' => static fn () => null,
        ]);

        // A long task would otherwise let the lock lease expire and get taken over.
        $scheduler->runDue(function () use (&$beats): void {
            $beats++;
        }, 5);

        $this->assertSame(2, $beats);
    }

    /**
     * @param array<string,callable> $runners
     */
    private function schedulerWith(array $runners): Scheduler
    {
        return new Scheduler(new SettingsService(), $runners);
    }

    private function backdate(string $task, int $seconds): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE scheduled_tasks SET last_run_at = NOW() - make_interval(secs => :secs) WHERE task = :task'
        );
        $stmt->execute(['secs' => $seconds, 'task' => $task]);
    }

    private function reflectRunnerExists(Scheduler $scheduler, string $name): bool
    {
        $method = new \ReflectionMethod($scheduler, 'runner');

        return $method->invoke($scheduler, $name) !== null;
    }
}
