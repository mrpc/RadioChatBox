<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use RadioChatBox\Services\StatsService;

/**
 * Drives every StatsService aggregation + period-read + maintenance method so the
 * SQL aggregation functions and the get* readers are exercised end to end (they
 * roll up existing data and are safely re-runnable against the shared database).
 */
class StatsServiceAggregationTest extends TestCase
{
    private StatsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new StatsService();
    }

    /** The five aggregations run without error over the current data. */
    public function testAggregationsRun(): void
    {
        $this->service->recordSnapshot(true);

        $this->assertIsBool($this->service->aggregateHourlyStats());
        $this->assertIsBool($this->service->aggregateDailyStats());
        $this->assertIsBool($this->service->aggregateWeeklyStats());
        $this->assertIsBool($this->service->aggregateMonthlyStats());
        $this->assertIsBool($this->service->aggregateYearlyStats());
    }

    /** Each period reader returns an array. */
    public function testPeriodReaders(): void
    {
        $this->assertIsArray($this->service->getHourlyStats());
        $this->assertIsArray($this->service->getDailyStats());
        $this->assertIsArray($this->service->getWeeklyStats());
        $this->assertIsArray($this->service->getMonthlyStats());
        $this->assertIsArray($this->service->getYearlyStats());
        $this->assertIsArray($this->service->getSummary());
    }

    /** The maintenance helpers run and report. */
    public function testMaintenanceHelpers(): void
    {
        $this->assertIsInt($this->service->cleanupOldSnapshots());
        $this->assertIsArray($this->service->runMaintenanceAggregations());
        $this->assertIsArray($this->service->triggerAggregationIfNeeded());
    }
}
