<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Migration;

/**
 * Track the cumulative reach of each promo campaign (total messages delivered),
 * so admins can measure how much promotion each campaign has driven. Additive;
 * after the promo_campaigns table.
 */
final class AddPromoSentCount extends Migration
{
    public $description = 'promo_campaigns.sent_count';
    public bool $transactional = true;
    public array $dependencies = ['create_schema'];

    public function up(): void
    {
        $s = $this->schema();
        if ($s->hasTable('promo_campaigns') && !$s->hasColumn('promo_campaigns', 'sent_count')) {
            $s->table('promo_campaigns', function ($t) {
                $t->integer('sent_count')->default(0);
            });
        }
    }

    public function down(): void
    {
        $s = $this->schema();
        if ($s->hasTable('promo_campaigns') && $s->hasColumn('promo_campaigns', 'sent_count')) {
            $s->table('promo_campaigns', function ($t) {
                $t->dropColumn('sent_count');
            });
        }
    }
}
