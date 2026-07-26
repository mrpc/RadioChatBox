<?php

/**
 * PramnosFramework application descriptor (coexistence bridge).
 *
 * Minimal for now: RadioChatBox is adopting the framework as infrastructure
 * incrementally (see docs/pramnos-migration/). Sections are added as each phase
 * lands:
 *   - 'migration_cutoff' — Phase 2 (baseline the existing schema so the tracked
 *     migration runner never recreates production tables).
 *   - 'middleware' / 'features' / 'broadcasting' / 'queue' / 'storage' — later phases.
 *
 * @return array<string,mixed>
 */

return [
    'name'      => 'RadioChatBox',
    'namespace' => 'RadioChatBox',
    'features'  => [],
    // Phase 2 will set this to a timestamp after the last baselined migration.
    'migration_cutoff' => '',
];
