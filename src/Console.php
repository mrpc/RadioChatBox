<?php

namespace RadioChatBox;

/**
 * RadioChatBox console application.
 *
 * The scaffolded-app convention (`src/Console.php` + a root `radiochatbox.php`
 * entry point): extend the framework console and register the app's commands in
 * registerCommands(). The bootstrap (a console-safe wiring that does NOT call
 * Application::init()) lives in the entry point.
 */
class Console extends \Pramnos\Console\Application
{
    protected function registerCommands(): void
    {
        parent::registerCommands();

        // The framework's daemon orchestrator is abstract; register the concrete
        // RadioChatBox subclass.
        $this->add(new \RadioChatBox\ConsoleCommands\RadioChatBoxDaemons());

        // Per-feature background workers (supervised by daemons:start).
        $this->add(new \RadioChatBox\ConsoleCommands\BotWorker());       // bot:start (optional)
        $this->add(new \RadioChatBox\ConsoleCommands\TrackerWorker());   // tracker:start
        $this->add(new \RadioChatBox\ConsoleCommands\StatsWorker());     // stats:start
        $this->add(new \RadioChatBox\ConsoleCommands\MaintenanceWorker()); // maintenance:start

        // Bot operational commands (retired the root-level worker.php).
        $this->add(new \RadioChatBox\ConsoleCommands\BotStatus());
        $this->add(new \RadioChatBox\ConsoleCommands\BotLog());
        $this->add(new \RadioChatBox\ConsoleCommands\BotSchedule());
        $this->add(new \RadioChatBox\ConsoleCommands\BotRunTask());
        $this->add(new \RadioChatBox\ConsoleCommands\BotPruneLog());
        $this->add(new \RadioChatBox\ConsoleCommands\BotFlush());
    }
}
