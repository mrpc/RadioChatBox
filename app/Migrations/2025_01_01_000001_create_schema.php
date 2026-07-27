<?php

namespace RadioChatBox\Migrations;

use Pramnos\Database\Blueprint;
use Pramnos\Database\Migration;

/**
 * Create the full RadioChatBox schema (single baseline migration).
 *
 * Tables, columns, primary keys, unique constraints, indexes (incl. partial /
 * DESC) and the native user_role enum are built with the schema builder. The
 * irreducibly PostgreSQL-specific parts — foreign keys (exact names), CHECK
 * constraints, the GIN index, plpgsql functions, triggers, views, column/table
 * comments — and the seed data are applied as raw SQL. Replaces the former
 * database/init.sql and the 22 incremental migrations. Runs once (tracked in
 * schemaversion).
 */
final class CreateSchema extends Migration
{
    public $description = 'Create the full RadioChatBox schema (baseline)';

    public bool $transactional = false;

    public function up(): void
    {
        $s = $this->schema();

        $s->createTable('admin_notification_reads', function ($t) {
            $t->increments('id');
            $t->integer('notification_id');
            $t->string('admin_username', 100);
            $t->timestampTz('read_at')->useCurrent();
            $t->unique(['notification_id','admin_username'], 'admin_notification_reads_notification_id_admin_username_key');
            $t->index(['admin_username'], 'idx_admin_notification_reads_admin');
            $t->index(['notification_id'], 'idx_admin_notification_reads_notification');
        });

        $s->createTable('admin_notifications', function ($t) {
            $t->increments('id');
            $t->string('notification_type', 50);
            $t->string('title', 255);
            $t->text('message');
            $t->jsonb('metadata')->nullable()->default('{}');
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['created_at DESC'], 'idx_admin_notifications_created_at');
            $t->index(['notification_type'], 'idx_admin_notifications_type');
        });

        $s->createTable('albums', function ($t) {
            $t->increments('id');
            $t->string('title', 400);
            $t->integer('artist_id')->nullable();
            $t->string('source', 20)->nullable();
            $t->string('external_id', 100)->nullable();
            $t->string('external_url', 1000)->nullable();
            $t->string('cover_file', 500)->nullable();
            $t->date('release_date')->nullable();
            $t->string('genre', 200)->nullable();
            $t->jsonb('meta')->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->timestampTz('updated_at')->useCurrent();
            $t->unique(['title','artist_id'], 'uq_albums_title_artist');
            $t->index(['artist_id'], 'idx_albums_artist');
        });

        $s->createTable('artists', function ($t) {
            $t->increments('id');
            $t->string('name', 300);
            $t->string('source', 20)->nullable();
            $t->string('external_id', 100)->nullable();
            $t->string('external_url', 1000)->nullable();
            $t->string('genre', 200)->nullable();
            $t->string('image_file', 500)->nullable();
            $t->jsonb('meta')->nullable();
            $t->boolean('excluded_from_stats')->default(false);
            $t->timestampTz('created_at')->useCurrent();
            $t->timestampTz('updated_at')->useCurrent();
            $t->unique(['name'], 'uq_artists_name');
        });

        $s->createTable('attachments', function ($t) {
            $t->increments('id');
            $t->string('attachment_id', 255);
            $t->string('filename', 255);
            $t->string('original_filename', 255);
            $t->string('file_path', 500);
            $t->integer('file_size');
            $t->string('mime_type', 100);
            $t->integer('width')->nullable();
            $t->integer('height')->nullable();
            $t->string('uploaded_by', 50);
            $t->string('recipient', 50)->nullable();
            $t->string('ip_address', 45);
            $t->timestampTz('uploaded_at')->useCurrent();
            $t->timestampTz('expires_at');
            $t->boolean('is_deleted')->nullable()->default(false);
            $t->unique(['attachment_id'], 'attachments_attachment_id_key');
            $t->index(['expires_at'], 'idx_attachments_expires_at');
            $t->index(['recipient'], 'idx_attachments_recipient');
            $t->index(['uploaded_at DESC'], 'idx_attachments_uploaded_at');
            $t->index(['uploaded_by'], 'idx_attachments_uploaded_by');
        });

        $s->createTable('banned_ips', function ($t) {
            $t->increments('id');
            $t->string('ip_address', 45);
            $t->text('reason')->nullable();
            $t->timestampTz('banned_at')->useCurrent();
            $t->timestampTz('banned_until')->nullable();
            $t->string('banned_by', 50)->nullable();
            $t->unique(['ip_address'], 'banned_ips_ip_address_key');
            $t->index(['banned_until'], 'idx_banned_ips_banned_until')->where('banned_until IS NOT NULL');
            $t->index(['ip_address'], 'idx_banned_ips_ip_address');
            $t->index(['ip_address'], 'idx_banned_ips_permanent')->where('banned_until IS NULL');
        });

        $s->createTable('banned_nicknames', function ($t) {
            $t->increments('id');
            $t->string('nickname', 50);
            $t->text('reason')->nullable();
            $t->timestampTz('banned_at')->useCurrent();
            $t->string('banned_by', 50)->nullable();
            $t->unique(['nickname'], 'banned_nicknames_nickname_key');
            $t->index(['nickname'], 'idx_banned_nicknames_nickname');
        });

        $s->createTable('bot_llm_balance', function ($t) {
            $t->bigIncrements('id');
            $t->timestampTz('created_at')->useCurrent();
            $t->string('provider', 30)->default('deepseek');
            $t->string('currency', 3);
            $t->decimal('total_balance', 14, 4);
            $t->decimal('granted_balance', 14, 4)->nullable();
            $t->decimal('topped_up_balance', 14, 4)->nullable();
            $t->index(['provider','created_at DESC'], 'idx_bot_llm_balance_created_at');
        });

        $s->createTable('bot_llm_log', function ($t) {
            $t->bigIncrements('id');
            $t->timestampTz('created_at')->useCurrent();
            $t->string('fake_nickname', 50)->nullable();
            $t->string('peer_username', 100)->nullable();
            $t->string('purpose', 30)->default('reply');
            $t->string('provider', 30)->nullable();
            $t->string('model', 100);
            $t->string('endpoint', 255)->nullable();
            $t->text('system_prompt')->nullable();
            $t->jsonb('messages')->nullable();
            $t->integer('max_tokens')->nullable();
            $t->decimal('temperature', 4, 2)->nullable();
            $t->boolean('reasoning')->default(false);
            $t->integer('http_status')->nullable();
            $t->string('finish_reason', 40)->nullable();
            $t->text('reply')->nullable();
            $t->jsonb('usage')->nullable();
            $t->integer('duration_ms')->nullable();
            $t->text('error')->nullable();
            $t->decimal('cost', 14, 8)->nullable();
            $t->string('currency', 3)->nullable();
            $t->index(['created_at DESC'], 'idx_bot_llm_log_created_at');
            $t->index(['created_at DESC'], 'idx_bot_llm_log_problems')->where('(error IS NOT NULL) OR ((finish_reason)::text <> \'stop\'::text)');
            $t->index(['fake_nickname','peer_username','created_at DESC'], 'idx_bot_llm_log_thread');
        });

        $s->createTable('bot_threads', function ($t) {
            $t->increments('id');
            $t->integer('fake_user_id');
            $t->string('peer_username', 100);
            $t->integer('messages_sent')->default(0);
            $t->boolean('is_taken_over')->default(false);
            $t->timestampTz('taken_over_at')->nullable();
            $t->string('taken_over_by', 100)->nullable();
            $t->timestampTz('farewell_sent_at')->nullable();
            $t->timestampTz('last_reply_at')->nullable();
            $t->text('last_error')->nullable();
            $t->boolean('is_ignored')->default(false);
            $t->timestampTz('ignore_decided_at')->nullable();
            $t->integer('insult_count')->default(0);
            $t->timestampTz('last_insult_at')->nullable();
            $t->timestampTz('blocked_at')->nullable();
            $t->text('summary')->nullable();
            $t->bigInteger('summary_upto_id')->nullable();
            $t->timestampTz('summary_updated_at')->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->timestampTz('updated_at')->useCurrent();
            $t->unique(['fake_user_id','peer_username'], 'uniq_bot_thread');
            $t->index(['peer_username'], 'idx_bot_threads_peer');
        });

        $s->createTable('dm_blocks', function ($t) {
            $t->increments('id');
            $t->string('blocker_username', 100);
            $t->integer('blocker_user_id')->nullable();
            $t->string('blocked_username', 100);
            $t->integer('blocked_user_id')->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->timestampTz('expires_at')->nullable();
            $t->unique(['blocker_username','blocked_username'], 'uq_dm_blocks_pair');
            $t->index(['blocked_username'], 'idx_dm_blocks_blocked');
            $t->index(['blocker_username'], 'idx_dm_blocks_blocker');
            $t->index(['expires_at'], 'idx_dm_blocks_expires');
        });

        $s->createTable('fake_users', function ($t) {
            $t->increments('id');
            $t->string('nickname', 50);
            $t->integer('age')->nullable();
            $t->string('sex', 10)->nullable();
            $t->string('location', 100)->nullable();
            $t->boolean('is_active')->nullable()->default(false);
            $t->timestampTz('created_at')->nullable()->useCurrent();
            $t->boolean('bot_enabled')->default(false);
            $t->text('bot_persona')->nullable();
            $t->text('bot_custom_prompt')->nullable();
            $t->integer('bot_max_messages')->nullable();
            $t->decimal('bot_typing_seconds_per_word', 4, 2)->nullable();
            $t->text('bot_farewell_messages')->nullable();
            $t->integer('bot_ignore_chance')->nullable();
            $t->string('bot_llm_provider', 30)->nullable();
            $t->string('bot_llm_model', 100)->nullable();
            $t->string('bot_reply_language', 20)->nullable();
            $t->text('bot_self_facts')->nullable();
            $t->unique(['nickname'], 'fake_users_nickname_key');
            $t->index(['is_active'], 'idx_fake_users_active');
            $t->index(['bot_enabled'], 'idx_fake_users_bot_enabled')->where('bot_enabled = true');
        });

        $s->createTable('message_reactions', function ($t) {
            $t->increments('id');
            $t->string('message_id', 255);
            $t->string('username', 100);
            $t->string('session_id', 255)->nullable();
            $t->string('emoji', 16);
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['message_id','username'], 'uq_message_reactions_user');
            $t->index(['message_id'], 'idx_message_reactions_message');
        });

        $s->createTable('messages', function ($t) {
            $t->increments('id');
            $t->string('message_id', 255);
            $t->string('username', 50);
            $t->text('message');
            $t->string('ip_address', 45);
            $t->timestampTz('created_at')->useCurrent();
            $t->boolean('is_deleted')->nullable()->default(false);
            $t->string('reply_to', 255)->nullable()->default(null);
            $t->integer('user_id')->nullable();
            $t->string('display_name', 100)->nullable();
            $t->timestampTz('edited_at')->nullable();
            $t->string('pinned_track', 500)->nullable()->default(null);
            $t->unique(['message_id'], 'messages_message_id_key');
            $t->index(['is_deleted','created_at DESC'], 'idx_messages_active_recent')->where('is_deleted = false');
            $t->index(['created_at DESC'], 'idx_messages_created_at');
            $t->index(['ip_address'], 'idx_messages_ip_address');
            $t->index(['message_id'], 'idx_messages_message_id');
            $t->index(['reply_to'], 'idx_messages_reply_to');
            $t->index(['user_id'], 'idx_messages_user');
            $t->index(['username'], 'idx_messages_username');
        });

        $s->createTable('private_messages', function ($t) {
            $t->increments('id');
            $t->string('from_username', 100);
            $t->string('to_username', 100);
            $t->text('message');
            $t->string('attachment_id', 255)->nullable();
            $t->timestampTz('created_at')->nullable()->useCurrent();
            $t->timestampTz('read_at')->nullable();
            $t->string('from_display_name', 100)->nullable();
            $t->string('to_display_name', 100)->nullable();
            $t->string('from_session_id', 255)->nullable();
            $t->string('to_session_id', 255)->nullable();
            $t->integer('from_user_id')->nullable();
            $t->integer('to_user_id')->nullable();
            $t->index(['from_username','to_username','from_session_id','to_session_id'], 'idx_pm_conversation');
            $t->index(['from_username','to_username','created_at DESC'], 'idx_pm_created_at');
            $t->index(['attachment_id'], 'idx_private_messages_attachment');
            $t->index(['from_username'], 'idx_private_messages_from');
            $t->index(['from_username','from_session_id'], 'idx_private_messages_from_session');
            $t->index(['from_user_id'], 'idx_private_messages_from_user');
            $t->index(['to_username'], 'idx_private_messages_to');
            $t->index(['to_username','to_session_id'], 'idx_private_messages_to_session');
            $t->index(['to_user_id'], 'idx_private_messages_to_user');
        });

        $s->createTable('scheduled_tasks', function ($t) {
            $t->string('task', 50);
            $t->timestampTz('last_run_at')->nullable();
            $t->string('last_status', 20)->nullable();
            $t->integer('last_duration_ms')->nullable();
            $t->text('last_error')->nullable();
            $t->bigInteger('runs')->default(0);
            $t->bigInteger('failures')->default(0);
            $t->timestampTz('created_at')->useCurrent();
            $t->primary(['task']);
            $t->index(['last_run_at DESC'], 'idx_scheduled_tasks_last_run');
        });

        $s->createTable('sessions', function ($t) {
            $t->increments('id');
            $t->string('username', 50);
            $t->string('session_id', 255);
            $t->string('ip_address', 45);
            $t->timestampTz('last_heartbeat')->useCurrent();
            $t->timestampTz('joined_at')->useCurrent();
            $t->integer('user_id')->nullable();
            $t->unique(['username','session_id'], 'sessions_username_session_unique');
            $t->index(['last_heartbeat'], 'idx_sessions_last_heartbeat');
            $t->index(['session_id'], 'idx_sessions_session_id');
            $t->index(['user_id'], 'idx_sessions_user');
            $t->index(['user_id'], 'idx_sessions_user_id')->where('user_id IS NOT NULL');
            $t->index(['username'], 'idx_sessions_username');
            $t->index(['session_id','username'], 'idx_sessions_validation');
        });

        $s->createTable('settings', function ($t) {
            $t->increments('id');
            $t->string('setting_key', 100);
            $t->text('setting_value')->nullable();
            $t->timestampTz('created_at')->nullable()->useCurrent();
            $t->timestampTz('updated_at')->nullable()->useCurrent();
            $t->unique(['setting_key'], 'settings_setting_key_key');
            $t->index(['setting_key','setting_value'], 'idx_settings_key_value');
        });

        $s->createTable('stats_daily', function ($t) {
            $t->increments('id');
            $t->date('stat_date');
            $t->integer('active_users')->nullable()->default(0);
            $t->integer('guest_users')->nullable()->default(0);
            $t->integer('registered_users')->nullable()->default(0);
            $t->integer('total_messages')->nullable()->default(0);
            $t->integer('private_messages')->nullable()->default(0);
            $t->integer('photo_uploads')->nullable()->default(0);
            $t->integer('new_registrations')->nullable()->default(0);
            $t->integer('radio_listeners_avg')->nullable()->default(0);
            $t->integer('radio_listeners_peak')->nullable()->default(0);
            $t->integer('peak_concurrent_users')->nullable()->default(0);
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['stat_date'], 'stats_daily_stat_date_key');
            $t->index(['stat_date DESC'], 'idx_stats_daily_stat_date');
        });

        $s->createTable('stats_hourly', function ($t) {
            $t->increments('id');
            $t->timestampTz('stat_hour');
            $t->integer('active_users')->nullable()->default(0);
            $t->integer('guest_users')->nullable()->default(0);
            $t->integer('registered_users')->nullable()->default(0);
            $t->integer('total_messages')->nullable()->default(0);
            $t->integer('private_messages')->nullable()->default(0);
            $t->integer('photo_uploads')->nullable()->default(0);
            $t->integer('new_registrations')->nullable()->default(0);
            $t->integer('radio_listeners_avg')->nullable()->default(0);
            $t->integer('radio_listeners_peak')->nullable()->default(0);
            $t->integer('peak_concurrent_users')->nullable()->default(0);
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['stat_hour'], 'stats_hourly_stat_hour_key');
            $t->index(['stat_hour DESC'], 'idx_stats_hourly_stat_hour');
        });

        $s->createTable('stats_monthly', function ($t) {
            $t->increments('id');
            $t->integer('stat_year');
            $t->integer('stat_month');
            $t->integer('active_users')->nullable()->default(0);
            $t->integer('guest_users')->nullable()->default(0);
            $t->integer('registered_users')->nullable()->default(0);
            $t->integer('total_messages')->nullable()->default(0);
            $t->integer('private_messages')->nullable()->default(0);
            $t->integer('photo_uploads')->nullable()->default(0);
            $t->integer('new_registrations')->nullable()->default(0);
            $t->integer('radio_listeners_avg')->nullable()->default(0);
            $t->integer('radio_listeners_peak')->nullable()->default(0);
            $t->integer('peak_concurrent_users')->nullable()->default(0);
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['stat_year','stat_month'], 'stats_monthly_stat_year_stat_month_key');
            $t->index(['stat_year DESC','stat_month DESC'], 'idx_stats_monthly_year_month');
        });

        $s->createTable('stats_snapshots', function ($t) {
            $t->increments('id');
            $t->timestampTz('snapshot_time')->useCurrent();
            $t->integer('concurrent_users')->nullable()->default(0);
            $t->integer('radio_listeners')->nullable()->default(0);
            $t->integer('active_sessions')->nullable()->default(0);
            $t->index(['snapshot_time DESC'], 'idx_stats_snapshots_time');
        });

        $s->createTable('stats_weekly', function ($t) {
            $t->increments('id');
            $t->integer('stat_year');
            $t->integer('stat_week');
            $t->date('week_start_date');
            $t->integer('active_users')->nullable()->default(0);
            $t->integer('guest_users')->nullable()->default(0);
            $t->integer('registered_users')->nullable()->default(0);
            $t->integer('total_messages')->nullable()->default(0);
            $t->integer('private_messages')->nullable()->default(0);
            $t->integer('photo_uploads')->nullable()->default(0);
            $t->integer('new_registrations')->nullable()->default(0);
            $t->integer('radio_listeners_avg')->nullable()->default(0);
            $t->integer('radio_listeners_peak')->nullable()->default(0);
            $t->integer('peak_concurrent_users')->nullable()->default(0);
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['stat_year','stat_week'], 'stats_weekly_stat_year_stat_week_key');
            $t->index(['week_start_date DESC'], 'idx_stats_weekly_week_start');
            $t->index(['stat_year DESC','stat_week DESC'], 'idx_stats_weekly_year_week');
        });

        $s->createTable('stats_yearly', function ($t) {
            $t->increments('id');
            $t->integer('stat_year');
            $t->integer('active_users')->nullable()->default(0);
            $t->integer('guest_users')->nullable()->default(0);
            $t->integer('registered_users')->nullable()->default(0);
            $t->integer('total_messages')->nullable()->default(0);
            $t->integer('private_messages')->nullable()->default(0);
            $t->integer('photo_uploads')->nullable()->default(0);
            $t->integer('new_registrations')->nullable()->default(0);
            $t->integer('radio_listeners_avg')->nullable()->default(0);
            $t->integer('radio_listeners_peak')->nullable()->default(0);
            $t->integer('peak_concurrent_users')->nullable()->default(0);
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['stat_year'], 'stats_yearly_stat_year_key');
            $t->index(['stat_year DESC'], 'idx_stats_yearly_year');
        });

        $s->createTable('track_plays', function ($t) {
            $t->increments('id');
            $t->integer('track_id');
            $t->integer('listeners')->nullable();
            $t->timestampTz('played_at')->useCurrent();
            $t->index(['played_at'], 'idx_track_plays_played_at');
            $t->index(['track_id'], 'idx_track_plays_track');
        });

        $s->createTable('tracks', function ($t) {
            $t->increments('id');
            $t->string('artist', 300)->nullable();
            $t->string('title', 300)->nullable();
            $t->string('display', 500)->nullable();
            $t->timestampTz('first_played_at')->useCurrent();
            $t->timestampTz('last_played_at')->useCurrent();
            $t->integer('play_count')->default(0);
            $t->integer('artist_id')->nullable();
            $t->integer('album_id')->nullable();
            $t->string('genre', 200)->nullable();
            $t->string('cover_file', 500)->nullable();
            $t->date('release_date')->nullable();
            $t->string('source', 20)->nullable();
            $t->string('external_id', 100)->nullable();
            $t->string('external_url', 1000)->nullable();
            $t->jsonb('meta')->nullable();
            $t->boolean('excluded_from_stats')->default(false);
            $t->timestampTz('enriched_at')->nullable();
            $t->unique(['display'], 'uq_tracks_display');
            $t->index(['album_id'], 'idx_tracks_album');
            $t->index(['artist_id'], 'idx_tracks_artist');
            $t->index(['enriched_at'], 'idx_tracks_enriched');
            $t->index(['genre'], 'idx_tracks_genre');
        });

        $s->createTable('url_blacklist', function ($t) {
            $t->increments('id');
            $t->string('pattern', 500);
            $t->text('description')->nullable();
            $t->string('added_by', 100)->nullable();
            $t->timestampTz('added_at')->nullable()->useCurrent();
            $t->unique(['pattern'], 'url_blacklist_pattern_key');
            $t->index(['pattern'], 'idx_url_blacklist_pattern');
        });

        $s->createTable('url_whitelist', function ($t) {
            $t->increments('id');
            $t->string('pattern', 500);
            $t->text('description')->nullable();
            $t->string('added_by', 100)->nullable()->default('admin');
            $t->timestamp('added_at')->nullable()->useCurrent();
            $t->unique(['pattern'], 'url_whitelist_pattern_key');
            $t->index(['pattern'], 'idx_url_whitelist_pattern');
        });

        $s->createTable('user_activity', function ($t) {
            $t->increments('id');
            $t->string('username', 50);
            $t->string('session_id', 255)->nullable();
            $t->string('ip_address', 45);
            $t->timestampTz('first_seen')->useCurrent();
            $t->timestampTz('last_seen')->useCurrent();
            $t->integer('message_count')->nullable()->default(0);
            $t->boolean('is_banned')->nullable()->default(false);
            $t->boolean('is_moderator')->nullable()->default(false);
            $t->integer('user_id')->nullable();
            $t->unique(['username'], 'user_activity_username_key');
            $t->index(['ip_address'], 'idx_user_activity_ip_address');
            $t->index(['user_id'], 'idx_user_activity_user');
            $t->index(['username'], 'idx_user_activity_username');
            $t->index(['username','last_seen','message_count'], 'idx_user_activity_username_stats');
        });

        $s->createTable('user_profiles', function ($t) {
            $t->increments('id');
            $t->string('username', 100);
            $t->string('session_id', 255);
            $t->string('age', 50)->nullable();
            $t->string('location', 255)->nullable();
            $t->string('sex', 20)->nullable();
            $t->timestampTz('created_at')->nullable()->useCurrent();
            $t->unique(['username','session_id'], 'user_profiles_username_session_id_key');
            $t->index(['username'], 'idx_user_profiles_username');
        });

        $s->createTable('users', function ($t) {
            $t->increments('id');
            $t->string('username', 50);
            $t->string('password_hash', 255);
            $t->enumType('role', 'user_role', ['root','administrator','moderator','simple_user']);
            $t->string('email', 255)->nullable();
            $t->string('display_name', 100)->nullable();
            $t->boolean('is_active')->nullable()->default(true);
            $t->timestampTz('created_at')->useCurrent();
            $t->timestampTz('updated_at')->useCurrent();
            $t->timestampTz('last_login')->nullable();
            $t->integer('created_by')->nullable();
            $t->unique(['username'], 'users_username_key');
            $t->index(['is_active','username','display_name'], 'idx_users_active_display')->where('is_active = true');
            $t->unique(['display_name'], 'idx_users_display_name_unique')->where('display_name IS NOT NULL');
            $t->unique(['email'], 'idx_users_email_unique')->where('email IS NOT NULL');
            $t->index(['is_active'], 'idx_users_is_active');
            $t->index(['role'], 'idx_users_role');
            $t->index(['username'], 'idx_users_username');
        });

        // --- plpgsql functions, views, triggers (raw; not expressible via the builder) ---
        $this->DB()->statement(<<<'SQL'
CREATE OR REPLACE FUNCTION public.aggregate_daily_stats(target_date date)
 RETURNS void
 LANGUAGE plpgsql
AS $function$
DECLARE
    v_active_users INTEGER;
    v_guest_users INTEGER;
    v_registered_users INTEGER;
    v_total_messages INTEGER;
    v_private_messages INTEGER;
    v_photo_uploads INTEGER;
    v_new_registrations INTEGER;
    v_radio_avg INTEGER;
    v_radio_peak INTEGER;
    v_peak_concurrent INTEGER;
    v_day_start TIMESTAMP;
    v_day_end TIMESTAMP;
BEGIN
    v_day_start := target_date::TIMESTAMP;
    v_day_end := v_day_start + INTERVAL '1 day';
    
    -- Sum from hourly stats (more efficient than raw data)
    SELECT
        MAX(active_users),
        MAX(guest_users),
        MAX(registered_users),
        SUM(total_messages),
        SUM(private_messages),
        SUM(photo_uploads),
        SUM(new_registrations),
        AVG(radio_listeners_avg)::INTEGER,
        MAX(radio_listeners_peak),
        MAX(peak_concurrent_users)
    INTO
        v_active_users, v_guest_users, v_registered_users,
        v_total_messages, v_private_messages, v_photo_uploads,
        v_new_registrations, v_radio_avg, v_radio_peak, v_peak_concurrent
    FROM stats_hourly
    WHERE stat_hour >= v_day_start AND stat_hour < v_day_end;
    
    INSERT INTO stats_daily (
        stat_date, active_users, guest_users, registered_users,
        total_messages, private_messages, photo_uploads,
        new_registrations, radio_listeners_avg, radio_listeners_peak,
        peak_concurrent_users
    )
    VALUES (
        target_date,
        COALESCE(v_active_users, 0),
        COALESCE(v_guest_users, 0),
        COALESCE(v_registered_users, 0),
        COALESCE(v_total_messages, 0),
        COALESCE(v_private_messages, 0),
        COALESCE(v_photo_uploads, 0),
        COALESCE(v_new_registrations, 0),
        COALESCE(v_radio_avg, 0),
        COALESCE(v_radio_peak, 0),
        COALESCE(v_peak_concurrent, 0)
    )
    ON CONFLICT (stat_date) DO UPDATE SET
        active_users = EXCLUDED.active_users,
        guest_users = EXCLUDED.guest_users,
        registered_users = EXCLUDED.registered_users,
        total_messages = EXCLUDED.total_messages,
        private_messages = EXCLUDED.private_messages,
        photo_uploads = EXCLUDED.photo_uploads,
        new_registrations = EXCLUDED.new_registrations,
        radio_listeners_avg = EXCLUDED.radio_listeners_avg,
        radio_listeners_peak = EXCLUDED.radio_listeners_peak,
        peak_concurrent_users = EXCLUDED.peak_concurrent_users;
END;
$function$
;
CREATE OR REPLACE FUNCTION public.aggregate_hourly_stats(target_hour timestamp without time zone)
 RETURNS void
 LANGUAGE plpgsql
AS $function$
DECLARE
    v_active_users INTEGER;
    v_guest_users INTEGER;
    v_registered_users INTEGER;
    v_total_messages INTEGER;
    v_private_messages INTEGER;
    v_photo_uploads INTEGER;
    v_new_registrations INTEGER;
    v_radio_listeners_avg INTEGER;
    v_radio_listeners_peak INTEGER;
    v_peak_concurrent INTEGER;
    v_hour_start TIMESTAMP;
    v_hour_end TIMESTAMP;
BEGIN
    v_hour_start := date_trunc('hour', target_hour);
    v_hour_end := v_hour_start + INTERVAL '1 hour';
    
    SELECT COUNT(DISTINCT username) INTO v_active_users
    FROM messages
    WHERE created_at >= v_hour_start AND created_at < v_hour_end AND is_deleted = FALSE;
    
    SELECT COUNT(DISTINCT m.username) INTO v_guest_users
    FROM messages m LEFT JOIN users u ON m.username = u.username
    WHERE m.created_at >= v_hour_start AND m.created_at < v_hour_end
      AND m.is_deleted = FALSE AND u.id IS NULL;
    
    v_registered_users := COALESCE(v_active_users, 0) - COALESCE(v_guest_users, 0);
    
    SELECT COUNT(*) INTO v_total_messages
    FROM messages
    WHERE created_at >= v_hour_start AND created_at < v_hour_end AND is_deleted = FALSE;
    
    SELECT COUNT(*) INTO v_private_messages
    FROM private_messages
    WHERE created_at >= v_hour_start AND created_at < v_hour_end;
    
    SELECT COUNT(*) INTO v_photo_uploads
    FROM attachments
    WHERE uploaded_at >= v_hour_start AND uploaded_at < v_hour_end AND is_deleted = FALSE;
    
    SELECT COUNT(*) INTO v_new_registrations
    FROM users
    WHERE created_at >= v_hour_start AND created_at < v_hour_end;
    
    SELECT COALESCE(AVG(radio_listeners)::INTEGER, 0) INTO v_radio_listeners_avg
    FROM stats_snapshots
    WHERE snapshot_time >= v_hour_start AND snapshot_time < v_hour_end;
    
    SELECT COALESCE(MAX(radio_listeners), 0) INTO v_radio_listeners_peak
    FROM stats_snapshots
    WHERE snapshot_time >= v_hour_start AND snapshot_time < v_hour_end;
    
    SELECT COALESCE(MAX(concurrent_users), 0) INTO v_peak_concurrent
    FROM stats_snapshots
    WHERE snapshot_time >= v_hour_start AND snapshot_time < v_hour_end;
    
    INSERT INTO stats_hourly (
        stat_hour, active_users, guest_users, registered_users,
        total_messages, private_messages, photo_uploads,
        new_registrations, radio_listeners_avg, radio_listeners_peak, peak_concurrent_users
    )
    VALUES (
        v_hour_start,
        COALESCE(v_active_users, 0),
        COALESCE(v_guest_users, 0),
        COALESCE(v_registered_users, 0),
        COALESCE(v_total_messages, 0),
        COALESCE(v_private_messages, 0),
        COALESCE(v_photo_uploads, 0),
        COALESCE(v_new_registrations, 0),
        COALESCE(v_radio_listeners_avg, 0),
        COALESCE(v_radio_listeners_peak, 0),
        COALESCE(v_peak_concurrent, 0)
    )
    ON CONFLICT (stat_hour) DO UPDATE SET
        active_users = EXCLUDED.active_users,
        guest_users = EXCLUDED.guest_users,
        registered_users = EXCLUDED.registered_users,
        total_messages = EXCLUDED.total_messages,
        private_messages = EXCLUDED.private_messages,
        photo_uploads = EXCLUDED.photo_uploads,
        new_registrations = EXCLUDED.new_registrations,
        radio_listeners_avg = EXCLUDED.radio_listeners_avg,
        radio_listeners_peak = EXCLUDED.radio_listeners_peak,
        peak_concurrent_users = EXCLUDED.peak_concurrent_users;
END;
$function$
;
CREATE OR REPLACE FUNCTION public.aggregate_hourly_stats(target_hour timestamp with time zone)
 RETURNS void
 LANGUAGE plpgsql
AS $function$
DECLARE
    v_active_users INTEGER;
    v_guest_users INTEGER;
    v_registered_users INTEGER;
    v_total_messages INTEGER;
    v_private_messages INTEGER;
    v_photo_uploads INTEGER;
    v_new_registrations INTEGER;
    v_radio_listeners_avg INTEGER;
    v_radio_listeners_peak INTEGER;
    v_peak_concurrent INTEGER;
    v_hour_start TIMESTAMPTZ;
    v_hour_end TIMESTAMPTZ;
BEGIN
    v_hour_start := date_trunc('hour', target_hour);
    v_hour_end := v_hour_start + INTERVAL '1 hour';
    
    SELECT COUNT(DISTINCT username) INTO v_active_users
    FROM messages
    WHERE created_at >= v_hour_start AND created_at < v_hour_end AND is_deleted = FALSE;
    
    SELECT COUNT(DISTINCT m.username) INTO v_guest_users
    FROM messages m LEFT JOIN users u ON m.username = u.username
    WHERE m.created_at >= v_hour_start AND m.created_at < v_hour_end
      AND m.is_deleted = FALSE AND u.id IS NULL;
    
    v_registered_users := COALESCE(v_active_users, 0) - COALESCE(v_guest_users, 0);
    
    SELECT COUNT(*) INTO v_total_messages
    FROM messages
    WHERE created_at >= v_hour_start AND created_at < v_hour_end AND is_deleted = FALSE;
    
    SELECT COUNT(*) INTO v_private_messages
    FROM private_messages
    WHERE created_at >= v_hour_start AND created_at < v_hour_end;
    
    SELECT COUNT(*) INTO v_photo_uploads
    FROM attachments
    WHERE uploaded_at >= v_hour_start AND uploaded_at < v_hour_end AND is_deleted = FALSE;
    
    SELECT COUNT(*) INTO v_new_registrations
    FROM users
    WHERE created_at >= v_hour_start AND created_at < v_hour_end;
    
    SELECT COALESCE(AVG(radio_listeners)::INTEGER, 0) INTO v_radio_listeners_avg
    FROM stats_snapshots
    WHERE snapshot_time >= v_hour_start AND snapshot_time < v_hour_end;
    
    SELECT COALESCE(MAX(radio_listeners), 0) INTO v_radio_listeners_peak
    FROM stats_snapshots
    WHERE snapshot_time >= v_hour_start AND snapshot_time < v_hour_end;
    
    SELECT COALESCE(MAX(concurrent_users), 0) INTO v_peak_concurrent
    FROM stats_snapshots
    WHERE snapshot_time >= v_hour_start AND snapshot_time < v_hour_end;
    
    INSERT INTO stats_hourly (
        stat_hour, active_users, guest_users, registered_users,
        total_messages, private_messages, photo_uploads,
        new_registrations, radio_listeners_avg, radio_listeners_peak, peak_concurrent_users
    )
    VALUES (
        v_hour_start,
        COALESCE(v_active_users, 0),
        COALESCE(v_guest_users, 0),
        COALESCE(v_registered_users, 0),
        COALESCE(v_total_messages, 0),
        COALESCE(v_private_messages, 0),
        COALESCE(v_photo_uploads, 0),
        COALESCE(v_new_registrations, 0),
        COALESCE(v_radio_listeners_avg, 0),
        COALESCE(v_radio_listeners_peak, 0),
        COALESCE(v_peak_concurrent, 0)
    )
    ON CONFLICT (stat_hour) DO UPDATE SET
        active_users = EXCLUDED.active_users,
        guest_users = EXCLUDED.guest_users,
        registered_users = EXCLUDED.registered_users,
        total_messages = EXCLUDED.total_messages,
        private_messages = EXCLUDED.private_messages,
        photo_uploads = EXCLUDED.photo_uploads,
        new_registrations = EXCLUDED.new_registrations,
        radio_listeners_avg = EXCLUDED.radio_listeners_avg,
        radio_listeners_peak = EXCLUDED.radio_listeners_peak,
        peak_concurrent_users = EXCLUDED.peak_concurrent_users;
END;
$function$
;
CREATE OR REPLACE FUNCTION public.aggregate_monthly_stats(target_date date)
 RETURNS void
 LANGUAGE plpgsql
AS $function$
DECLARE
    v_year INTEGER;
    v_month INTEGER;
    v_month_start DATE;
    v_month_end DATE;
    v_active_users INTEGER;
    v_guest_users INTEGER;
    v_registered_users INTEGER;
    v_total_messages INTEGER;
    v_private_messages INTEGER;
    v_photo_uploads INTEGER;
    v_new_registrations INTEGER;
    v_radio_avg INTEGER;
    v_radio_peak INTEGER;
    v_peak_concurrent INTEGER;
BEGIN
    v_year := EXTRACT(YEAR FROM target_date);
    v_month := EXTRACT(MONTH FROM target_date);
    v_month_start := date_trunc('month', target_date)::DATE;
    v_month_end := (v_month_start + INTERVAL '1 month')::DATE;
    
    SELECT
        MAX(active_users),
        MAX(guest_users),
        MAX(registered_users),
        SUM(total_messages),
        SUM(private_messages),
        SUM(photo_uploads),
        SUM(new_registrations),
        AVG(radio_listeners_avg)::INTEGER,
        MAX(radio_listeners_peak),
        MAX(peak_concurrent_users)
    INTO
        v_active_users, v_guest_users, v_registered_users,
        v_total_messages, v_private_messages, v_photo_uploads,
        v_new_registrations, v_radio_avg, v_radio_peak, v_peak_concurrent
    FROM stats_daily
    WHERE stat_date >= v_month_start AND stat_date < v_month_end;
    
    INSERT INTO stats_monthly (
        stat_year, stat_month,
        active_users, guest_users, registered_users,
        total_messages, private_messages, photo_uploads,
        new_registrations, radio_listeners_avg, radio_listeners_peak,
        peak_concurrent_users
    )
    VALUES (
        v_year, v_month,
        COALESCE(v_active_users, 0),
        COALESCE(v_guest_users, 0),
        COALESCE(v_registered_users, 0),
        COALESCE(v_total_messages, 0),
        COALESCE(v_private_messages, 0),
        COALESCE(v_photo_uploads, 0),
        COALESCE(v_new_registrations, 0),
        COALESCE(v_radio_avg, 0),
        COALESCE(v_radio_peak, 0),
        COALESCE(v_peak_concurrent, 0)
    )
    ON CONFLICT (stat_year, stat_month) DO UPDATE SET
        active_users = EXCLUDED.active_users,
        guest_users = EXCLUDED.guest_users,
        registered_users = EXCLUDED.registered_users,
        total_messages = EXCLUDED.total_messages,
        private_messages = EXCLUDED.private_messages,
        photo_uploads = EXCLUDED.photo_uploads,
        new_registrations = EXCLUDED.new_registrations,
        radio_listeners_avg = EXCLUDED.radio_listeners_avg,
        radio_listeners_peak = EXCLUDED.radio_listeners_peak,
        peak_concurrent_users = EXCLUDED.peak_concurrent_users;
END;
$function$
;
CREATE OR REPLACE FUNCTION public.aggregate_weekly_stats(target_date date)
 RETURNS void
 LANGUAGE plpgsql
AS $function$
DECLARE
    v_year INTEGER;
    v_week INTEGER;
    v_week_start DATE;
    v_week_end DATE;
    v_active_users INTEGER;
    v_guest_users INTEGER;
    v_registered_users INTEGER;
    v_total_messages INTEGER;
    v_private_messages INTEGER;
    v_photo_uploads INTEGER;
    v_new_registrations INTEGER;
    v_radio_avg INTEGER;
    v_radio_peak INTEGER;
    v_peak_concurrent INTEGER;
BEGIN
    -- Get ISO year and week
    v_year := EXTRACT(ISOYEAR FROM target_date);
    v_week := EXTRACT(WEEK FROM target_date);
    v_week_start := date_trunc('week', target_date)::DATE;
    v_week_end := v_week_start + INTERVAL '1 week';
    
    SELECT
        MAX(active_users),
        MAX(guest_users),
        MAX(registered_users),
        SUM(total_messages),
        SUM(private_messages),
        SUM(photo_uploads),
        SUM(new_registrations),
        AVG(radio_listeners_avg)::INTEGER,
        MAX(radio_listeners_peak),
        MAX(peak_concurrent_users)
    INTO
        v_active_users, v_guest_users, v_registered_users,
        v_total_messages, v_private_messages, v_photo_uploads,
        v_new_registrations, v_radio_avg, v_radio_peak, v_peak_concurrent
    FROM stats_daily
    WHERE stat_date >= v_week_start AND stat_date < v_week_end::DATE;
    
    INSERT INTO stats_weekly (
        stat_year, stat_week, week_start_date,
        active_users, guest_users, registered_users,
        total_messages, private_messages, photo_uploads,
        new_registrations, radio_listeners_avg, radio_listeners_peak,
        peak_concurrent_users
    )
    VALUES (
        v_year, v_week, v_week_start,
        COALESCE(v_active_users, 0),
        COALESCE(v_guest_users, 0),
        COALESCE(v_registered_users, 0),
        COALESCE(v_total_messages, 0),
        COALESCE(v_private_messages, 0),
        COALESCE(v_photo_uploads, 0),
        COALESCE(v_new_registrations, 0),
        COALESCE(v_radio_avg, 0),
        COALESCE(v_radio_peak, 0),
        COALESCE(v_peak_concurrent, 0)
    )
    ON CONFLICT (stat_year, stat_week) DO UPDATE SET
        active_users = EXCLUDED.active_users,
        guest_users = EXCLUDED.guest_users,
        registered_users = EXCLUDED.registered_users,
        total_messages = EXCLUDED.total_messages,
        private_messages = EXCLUDED.private_messages,
        photo_uploads = EXCLUDED.photo_uploads,
        new_registrations = EXCLUDED.new_registrations,
        radio_listeners_avg = EXCLUDED.radio_listeners_avg,
        radio_listeners_peak = EXCLUDED.radio_listeners_peak,
        peak_concurrent_users = EXCLUDED.peak_concurrent_users;
END;
$function$
;
CREATE OR REPLACE FUNCTION public.aggregate_yearly_stats(target_year integer)
 RETURNS void
 LANGUAGE plpgsql
AS $function$
DECLARE
    v_year_start DATE;
    v_year_end DATE;
    v_active_users INTEGER;
    v_guest_users INTEGER;
    v_registered_users INTEGER;
    v_total_messages INTEGER;
    v_private_messages INTEGER;
    v_photo_uploads INTEGER;
    v_new_registrations INTEGER;
    v_radio_avg INTEGER;
    v_radio_peak INTEGER;
    v_peak_concurrent INTEGER;
BEGIN
    v_year_start := (target_year || '-01-01')::DATE;
    v_year_end := (target_year + 1 || '-01-01')::DATE;
    
    SELECT
        MAX(active_users),
        MAX(guest_users),
        MAX(registered_users),
        SUM(total_messages),
        SUM(private_messages),
        SUM(photo_uploads),
        SUM(new_registrations),
        AVG(radio_listeners_avg)::INTEGER,
        MAX(radio_listeners_peak),
        MAX(peak_concurrent_users)
    INTO
        v_active_users, v_guest_users, v_registered_users,
        v_total_messages, v_private_messages, v_photo_uploads,
        v_new_registrations, v_radio_avg, v_radio_peak, v_peak_concurrent
    FROM stats_daily
    WHERE stat_date >= v_year_start AND stat_date < v_year_end;
    
    INSERT INTO stats_yearly (
        stat_year,
        active_users, guest_users, registered_users,
        total_messages, private_messages, photo_uploads,
        new_registrations, radio_listeners_avg, radio_listeners_peak,
        peak_concurrent_users
    )
    VALUES (
        target_year,
        COALESCE(v_active_users, 0),
        COALESCE(v_guest_users, 0),
        COALESCE(v_registered_users, 0),
        COALESCE(v_total_messages, 0),
        COALESCE(v_private_messages, 0),
        COALESCE(v_photo_uploads, 0),
        COALESCE(v_new_registrations, 0),
        COALESCE(v_radio_avg, 0),
        COALESCE(v_radio_peak, 0),
        COALESCE(v_peak_concurrent, 0)
    )
    ON CONFLICT (stat_year) DO UPDATE SET
        active_users = EXCLUDED.active_users,
        guest_users = EXCLUDED.guest_users,
        registered_users = EXCLUDED.registered_users,
        total_messages = EXCLUDED.total_messages,
        private_messages = EXCLUDED.private_messages,
        photo_uploads = EXCLUDED.photo_uploads,
        new_registrations = EXCLUDED.new_registrations,
        radio_listeners_avg = EXCLUDED.radio_listeners_avg,
        radio_listeners_peak = EXCLUDED.radio_listeners_peak,
        peak_concurrent_users = EXCLUDED.peak_concurrent_users;
END;
$function$
;
CREATE OR REPLACE FUNCTION public.cleanup_inactive_sessions()
 RETURNS integer
 LANGUAGE plpgsql
AS $function$
DECLARE
    deleted_count INTEGER;
BEGIN
    DELETE FROM sessions
    WHERE last_heartbeat < NOW() - INTERVAL '5 minutes';
    
    GET DIAGNOSTICS deleted_count = ROW_COUNT;
    RETURN deleted_count;
END;
$function$
;
CREATE OR REPLACE FUNCTION public.cleanup_old_notifications()
 RETURNS integer
 LANGUAGE plpgsql
AS $function$
DECLARE
    v_count INTEGER;
BEGIN
    DELETE FROM admin_notifications
    WHERE created_at < NOW() - INTERVAL '30 days'
      AND EXISTS (
        -- Only delete if at least one admin has read it
        SELECT 1 FROM admin_notification_reads r WHERE r.notification_id = admin_notifications.id
      );

    GET DIAGNOSTICS v_count = ROW_COUNT;
    RETURN v_count;
END;
$function$
;
CREATE OR REPLACE FUNCTION public.cleanup_old_snapshots()
 RETURNS void
 LANGUAGE plpgsql
AS $function$
BEGIN
    DELETE FROM stats_snapshots
    WHERE snapshot_time < NOW() - INTERVAL '30 days';
END;
$function$
;
CREATE OR REPLACE FUNCTION public.create_fake_user_dm_notification(p_from_username character varying, p_to_username character varying, p_message_preview text, p_message_id integer)
 RETURNS integer
 LANGUAGE plpgsql
AS $function$
DECLARE
    v_notification_id INTEGER;
    v_existing_unread INTEGER;
BEGIN
    -- Check if there's already an unread notification for this conversation
    -- Only create a new one if all existing notifications have been read by all admins
    SELECT n.id INTO v_existing_unread
    FROM admin_notifications n
    WHERE n.notification_type = 'fake_user_dm'
      AND n.metadata->>'from_username' = p_from_username
      AND n.metadata->>'to_username' = p_to_username
      AND n.created_at > NOW() - INTERVAL '30 days'
      AND NOT EXISTS (
          SELECT 1 FROM admin_notification_reads r
          WHERE r.notification_id = n.id
      )
    ORDER BY n.created_at DESC
    LIMIT 1;

    -- If there's an existing unread notification, update it and return its ID
    IF v_existing_unread IS NOT NULL THEN
        UPDATE admin_notifications
        SET message = p_from_username || ' sent a message to fake user ' || p_to_username || ': ' || LEFT(p_message_preview, 100),
            metadata = jsonb_set(
                jsonb_set(metadata, '{message_id}', to_jsonb(p_message_id)),
                '{message_preview}', to_jsonb(LEFT(p_message_preview, 200))
            ),
            created_at = NOW()  -- Update timestamp to move it to top
        WHERE id = v_existing_unread;
        
        RETURN v_existing_unread;
    END IF;

    -- No existing unread notification, create a new one
    INSERT INTO admin_notifications (
        notification_type,
        title,
        message,
        metadata
    )
    VALUES (
        'fake_user_dm',
        'New DM to fake user: ' || p_to_username,
        p_from_username || ' sent a message to fake user ' || p_to_username || ': ' || LEFT(p_message_preview, 100),
        jsonb_build_object(
            'from_username', p_from_username,
            'to_username', p_to_username,
            'message_id', p_message_id,
            'message_preview', LEFT(p_message_preview, 200)
        )
    )
    RETURNING id INTO v_notification_id;

    RETURN v_notification_id;
END;
$function$
;
CREATE OR REPLACE FUNCTION public.get_unread_notification_count(p_admin_username character varying)
 RETURNS integer
 LANGUAGE plpgsql
AS $function$
DECLARE
    v_count INTEGER;
BEGIN
    SELECT COUNT(*)
    INTO v_count
    FROM admin_notifications n
    WHERE NOT EXISTS (
        SELECT 1 FROM admin_notification_reads r
        WHERE r.notification_id = n.id
          AND r.admin_username = p_admin_username
    );

    RETURN v_count;
END;
$function$
;
CREATE OR REPLACE FUNCTION public.mark_all_notifications_read(p_admin_username character varying)
 RETURNS integer
 LANGUAGE plpgsql
AS $function$
DECLARE
    v_count INTEGER;
BEGIN
    -- Mark all unread notifications as read for this admin
    INSERT INTO admin_notification_reads (notification_id, admin_username)
    SELECT n.id, p_admin_username
    FROM admin_notifications n
    WHERE NOT EXISTS (
        SELECT 1 FROM admin_notification_reads r
        WHERE r.notification_id = n.id
          AND r.admin_username = p_admin_username
    );

    GET DIAGNOSTICS v_count = ROW_COUNT;
    RETURN v_count;
END;
$function$
;
CREATE OR REPLACE FUNCTION public.mark_notification_read(p_notification_id integer, p_admin_username character varying)
 RETURNS boolean
 LANGUAGE plpgsql
AS $function$
BEGIN
    -- Insert or update read state for this admin
    INSERT INTO admin_notification_reads (notification_id, admin_username)
    VALUES (p_notification_id, p_admin_username)
    ON CONFLICT (notification_id, admin_username) DO NOTHING;

    RETURN FOUND;
END;
$function$
;
CREATE OR REPLACE FUNCTION public.update_user_stats()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
BEGIN
    INSERT INTO user_activity (username, ip_address, last_seen, message_count, user_id)
    VALUES (NEW.username, NEW.ip_address, NEW.created_at, 1, NEW.user_id)
    ON CONFLICT (username) 
    DO UPDATE SET
        last_seen = NEW.created_at,
        message_count = user_activity.message_count + 1,
        ip_address = NEW.ip_address,
        user_id = COALESCE(NEW.user_id, user_activity.user_id);
    
    RETURN NEW;
END;
$function$
;
CREATE OR REPLACE FUNCTION public.update_users_updated_at()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$function$
;

CREATE OR REPLACE VIEW recent_messages AS  SELECT id,
    message_id,
    username,
    message,
    ip_address,
    created_at
   FROM messages
  WHERE ((created_at > (now() - '24:00:00'::interval)) AND (is_deleted = false))
  ORDER BY created_at DESC;
CREATE OR REPLACE VIEW user_stats AS  SELECT username,
    ip_address,
    first_seen,
    last_seen,
    message_count,
    is_banned,
    is_moderator,
    user_id
   FROM user_activity
  ORDER BY last_seen DESC;

CREATE TRIGGER trigger_update_user_stats AFTER INSERT ON public.messages FOR EACH ROW EXECUTE FUNCTION update_user_stats();
CREATE TRIGGER trigger_users_updated_at BEFORE UPDATE ON public.users FOR EACH ROW EXECUTE FUNCTION update_users_updated_at();
SQL);

        // --- foreign keys, CHECK constraints, GIN index (raw, exact names) ---
        $this->DB()->statement(<<<'SQL'
CREATE INDEX idx_admin_notifications_metadata ON public.admin_notifications USING gin (metadata);
ALTER TABLE admin_notification_reads ADD CONSTRAINT admin_notification_reads_notification_id_fkey FOREIGN KEY (notification_id) REFERENCES admin_notifications(id) ON DELETE CASCADE;
ALTER TABLE albums ADD CONSTRAINT albums_artist_id_fkey FOREIGN KEY (artist_id) REFERENCES artists(id) ON DELETE SET NULL;
ALTER TABLE bot_threads ADD CONSTRAINT bot_threads_fake_user_id_fkey FOREIGN KEY (fake_user_id) REFERENCES fake_users(id) ON DELETE CASCADE;
ALTER TABLE dm_blocks ADD CONSTRAINT dm_blocks_blocker_user_id_fkey FOREIGN KEY (blocker_user_id) REFERENCES users(id) ON DELETE CASCADE;
ALTER TABLE dm_blocks ADD CONSTRAINT dm_blocks_blocked_user_id_fkey FOREIGN KEY (blocked_user_id) REFERENCES users(id) ON DELETE CASCADE;
ALTER TABLE message_reactions ADD CONSTRAINT fk_message_reactions_message FOREIGN KEY (message_id) REFERENCES messages(message_id) ON DELETE CASCADE;
ALTER TABLE messages ADD CONSTRAINT fk_messages_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE messages ADD CONSTRAINT messages_user_id_fkey FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE private_messages ADD CONSTRAINT private_messages_to_user_id_fkey FOREIGN KEY (to_user_id) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE private_messages ADD CONSTRAINT private_messages_from_user_id_fkey FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE sessions ADD CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE track_plays ADD CONSTRAINT track_plays_track_id_fkey FOREIGN KEY (track_id) REFERENCES tracks(id) ON DELETE CASCADE;
ALTER TABLE tracks ADD CONSTRAINT tracks_album_id_fkey FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE SET NULL;
ALTER TABLE tracks ADD CONSTRAINT tracks_artist_id_fkey FOREIGN KEY (artist_id) REFERENCES artists(id) ON DELETE SET NULL;
ALTER TABLE user_activity ADD CONSTRAINT fk_user_activity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE users ADD CONSTRAINT users_created_by_fkey FOREIGN KEY (created_by) REFERENCES users(id);
ALTER TABLE fake_users ADD CONSTRAINT fake_users_age_check CHECK (((age >= 18) AND (age <= 99)));
ALTER TABLE fake_users ADD CONSTRAINT fake_users_sex_check CHECK (((sex)::text = ANY ((ARRAY['male'::character varying, 'female'::character varying, 'other'::character varying])::text[])));
ALTER TABLE fake_users ADD CONSTRAINT valid_nickname CHECK ((length((nickname)::text) >= 3));
ALTER TABLE fake_users ADD CONSTRAINT valid_bot_typing_speed CHECK (((bot_typing_seconds_per_word IS NULL) OR ((bot_typing_seconds_per_word >= (0)::numeric) AND (bot_typing_seconds_per_word <= (10)::numeric))));
ALTER TABLE fake_users ADD CONSTRAINT valid_bot_max_messages CHECK (((bot_max_messages IS NULL) OR ((bot_max_messages >= 0) AND (bot_max_messages <= 100))));
ALTER TABLE fake_users ADD CONSTRAINT valid_bot_reply_language CHECK (((bot_reply_language IS NULL) OR ((bot_reply_language)::text = ANY ((ARRAY['auto'::character varying, 'greek'::character varying, 'greeklish'::character varying, 'english'::character varying])::text[]))));
ALTER TABLE fake_users ADD CONSTRAINT valid_bot_ignore_chance CHECK (((bot_ignore_chance IS NULL) OR ((bot_ignore_chance >= 0) AND (bot_ignore_chance <= 100))));
ALTER TABLE users ADD CONSTRAINT username_length CHECK ((length((username)::text) >= 3));
ALTER TABLE users ADD CONSTRAINT display_name_length CHECK (((display_name IS NULL) OR (length((display_name)::text) >= 1)));
ALTER TABLE users ADD CONSTRAINT valid_email CHECK (((email IS NULL) OR ((email)::text ~* '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$'::text)));
ALTER TABLE attachments ALTER COLUMN expires_at SET DEFAULT (CURRENT_TIMESTAMP + '48:00:00'::interval);
ALTER TABLE users ALTER COLUMN role SET DEFAULT 'simple_user'::user_role;
SQL);

        // --- table/column comments ---
        $this->DB()->statement(<<<'SQL'
COMMENT ON TABLE user_activity IS 'Historical participation tracking - auto-populated by trigger when messages are sent (audit log of all chat participants)';
COMMENT ON TABLE sessions IS 'Currently active chat sessions - includes both anonymous users (user_id NULL) and authenticated users (user_id NOT NULL)';
COMMENT ON TABLE admin_notifications IS 'Notifications for admin panel - each notification is shared across all admins';
COMMENT ON TABLE admin_notification_reads IS 'Tracks which admins have read which notifications - per-admin read states';
COMMENT ON TABLE attachments IS 'Photo attachments for private messages (auto-expire after 48h)';
COMMENT ON TABLE user_profiles IS 'Optional user demographics (age, sex, location)';
COMMENT ON TABLE settings IS 'System configuration stored in database';
COMMENT ON TABLE users IS 'Authenticated user accounts with passwords and role-based access (admins, moderators, future registered chat users). Supports email login when email is provided.';
COMMENT ON TABLE messages IS 'Public chat messages';
COMMENT ON TABLE private_messages IS 'Private user-to-user messages';
COMMENT ON TABLE stats_hourly IS 'Hourly aggregated statistics - one row per hour';
COMMENT ON TABLE stats_daily IS 'Daily aggregated statistics - one row per day';
COMMENT ON TABLE stats_weekly IS 'Weekly aggregated statistics - one row per week (ISO week numbering)';
COMMENT ON TABLE stats_monthly IS 'Monthly aggregated statistics - one row per month';
COMMENT ON TABLE stats_yearly IS 'Yearly aggregated statistics - one row per year';
COMMENT ON TABLE stats_snapshots IS 'Real-time snapshots taken every 5-15 minutes for calculating averages and peaks. Note: concurrent_users and radio_listeners are SEPARATE services - someone can listen to radio without being in chat, and vice versa.';
COMMENT ON TABLE url_whitelist IS 'Stores URL patterns that are allowed in public chat messages. URLs matching these patterns will not be replaced with ***.';
COMMENT ON TABLE dm_blocks IS 'User-initiated DM blocks. Enforced mutually: a row (A,B) blocks DMs in both directions between A and B. Guest-created blocks expire via expires_at.';
COMMENT ON TABLE message_reactions IS 'Emoji reactions on public messages. One reaction per (message, user); a new emoji replaces the previous one.';
COMMENT ON TABLE track_plays IS 'One row per track play (detected now-playing change), referencing tracks.';
COMMENT ON TABLE fake_users IS 'Fake users that can be activated to fill the chat when real user count is low';
COMMENT ON TABLE artists IS 'Unique artists with metadata; excluded_from_stats hides them from stats (still logged).';
COMMENT ON TABLE albums IS 'Albums with metadata, linked to an artist.';
COMMENT ON TABLE tracks IS 'Unique tracks seen on the radio stream (keyed by display string).';
COMMENT ON TABLE bot_threads IS 'Per-conversation state for fake user auto-replies (message budget, admin takeover, summary)';
COMMENT ON TABLE bot_llm_log IS 'Request/response log of every LLM call made for fake user auto-replies';
COMMENT ON TABLE bot_llm_balance IS 'Periodic readings of the LLM provider balance (GET /user/balance); consecutive drops are real spend';
COMMENT ON TABLE scheduled_tasks IS 'Last run and outcome of each periodic task the bot worker runs (see src/Scheduler.php)';
COMMENT ON COLUMN user_activity.user_id IS 'References authenticated user account (NULL for anonymous participants, NOT NULL for registered users)';
COMMENT ON COLUMN sessions.user_id IS 'References authenticated user account (NULL for anonymous users, NOT NULL for logged-in users)';
COMMENT ON COLUMN admin_notifications.notification_type IS 'Type of notification (fake_user_dm, report, suspicious_activity, etc.)';
COMMENT ON COLUMN admin_notifications.title IS 'Short notification title';
COMMENT ON COLUMN admin_notifications.message IS 'Full notification message/description';
COMMENT ON COLUMN admin_notifications.metadata IS 'Additional structured data (user_ids, message_ids, context)';
COMMENT ON COLUMN admin_notification_reads.notification_id IS 'Reference to the notification';
COMMENT ON COLUMN admin_notification_reads.admin_username IS 'Username of admin who read this notification';
COMMENT ON COLUMN admin_notification_reads.read_at IS 'When this admin marked it as read';
COMMENT ON COLUMN attachments.file_size IS 'File size in bytes';
COMMENT ON COLUMN attachments.expires_at IS 'Photos auto-delete after 48 hours';
COMMENT ON COLUMN users.email IS 'Email address for login - must be unique when not null';
COMMENT ON COLUMN users.display_name IS 'Optional display name shown in chat - must be unique when set, falls back to username if null';
COMMENT ON COLUMN messages.reply_to IS 'References the message_id of the parent message being replied to';
COMMENT ON COLUMN messages.user_id IS 'User ID if message sent by registered user (NULL for anonymous/session-only users)';
COMMENT ON COLUMN messages.display_name IS 'Display name at time message was sent (snapshot for audit trail - UI shows current display_name via JOIN)';
COMMENT ON COLUMN messages.edited_at IS 'Timestamp of last edit by the message author. NULL means never edited.';
COMMENT ON COLUMN messages.pinned_track IS 'Snapshot of the now-playing track pinned to this message (NULL if none).';
COMMENT ON COLUMN private_messages.from_display_name IS 'Sender display name at time message was sent (snapshot for audit trail - UI shows current display_name via JOIN)';
COMMENT ON COLUMN private_messages.to_display_name IS 'Recipient display name at time message was sent (snapshot for audit trail - UI shows current display_name via JOIN)';
COMMENT ON COLUMN private_messages.from_session_id IS 'Session ID of sender - ensures message isolation between different users using same username';
COMMENT ON COLUMN private_messages.to_session_id IS 'Session ID of recipient - ensures message isolation between different users using same username';
COMMENT ON COLUMN private_messages.from_user_id IS 'User ID of sender if logged in as registered user (NULL for anonymous/session-only users)';
COMMENT ON COLUMN private_messages.to_user_id IS 'User ID of recipient if logged in as registered user (NULL for anonymous/session-only users)';
COMMENT ON COLUMN stats_hourly.stat_hour IS 'Start of the hour (e.g., 2024-01-15 14:00:00)';
COMMENT ON COLUMN stats_hourly.active_users IS 'Total unique users who sent messages during this hour';
COMMENT ON COLUMN stats_hourly.guest_users IS 'Unique anonymous users who sent messages';
COMMENT ON COLUMN stats_hourly.registered_users IS 'Unique authenticated users who sent messages';
COMMENT ON COLUMN stats_hourly.total_messages IS 'Count of public chat messages sent';
COMMENT ON COLUMN stats_hourly.private_messages IS 'Count of private messages sent';
COMMENT ON COLUMN stats_hourly.photo_uploads IS 'Count of photos uploaded';
COMMENT ON COLUMN stats_hourly.new_registrations IS 'Count of new user accounts created';
COMMENT ON COLUMN stats_hourly.radio_listeners_avg IS 'Average radio listeners during this hour';
COMMENT ON COLUMN stats_hourly.radio_listeners_peak IS 'Peak radio listeners during the hour';
COMMENT ON COLUMN stats_hourly.peak_concurrent_users IS 'Maximum concurrent users online at any moment';
COMMENT ON COLUMN stats_daily.radio_listeners_avg IS 'Average radio listeners throughout the day';
COMMENT ON COLUMN stats_daily.radio_listeners_peak IS 'Peak radio listeners during the day';
COMMENT ON COLUMN stats_weekly.stat_week IS 'ISO week number (1-53)';
COMMENT ON COLUMN stats_weekly.week_start_date IS 'Monday of the week';
COMMENT ON COLUMN stats_snapshots.concurrent_users IS 'Number of CHAT users in sessions table at snapshot time (excludes radio-only listeners)';
COMMENT ON COLUMN stats_snapshots.radio_listeners IS 'Number of radio stream listeners from Shoutcast/Icecast API (excludes chat-only users)';
COMMENT ON COLUMN stats_snapshots.active_sessions IS 'Total active chat sessions (includes duplicates from multiple tabs)';
COMMENT ON COLUMN url_whitelist.pattern IS 'URL pattern (domain or wildcard like *.example.com)';
COMMENT ON COLUMN url_whitelist.description IS 'Human-readable description of this pattern';
COMMENT ON COLUMN url_whitelist.added_by IS 'Username who added this pattern';
COMMENT ON COLUMN url_whitelist.added_at IS 'Timestamp when pattern was added';
COMMENT ON COLUMN dm_blocks.blocker_username IS 'The user who created the block.';
COMMENT ON COLUMN dm_blocks.blocked_username IS 'The user who was blocked.';
COMMENT ON COLUMN dm_blocks.expires_at IS 'When the block auto-expires. NULL = permanent (registered blocker); set for guest blockers.';
COMMENT ON COLUMN fake_users.is_active IS 'Whether this fake user is currently shown in the user list';
COMMENT ON COLUMN fake_users.bot_enabled IS 'Whether this fake user auto-replies to private messages via the LLM';
COMMENT ON COLUMN fake_users.bot_persona IS 'Extra personality/interests appended to the generated system prompt';
COMMENT ON COLUMN fake_users.bot_custom_prompt IS 'Full persona override; when set, replaces the generated persona';
COMMENT ON COLUMN fake_users.bot_max_messages IS 'Per-bot override of bot_max_messages_per_thread (NULL = use global setting)';
COMMENT ON COLUMN fake_users.bot_typing_seconds_per_word IS 'Per-bot override of bot_typing_seconds_per_word (NULL = use global setting)';
COMMENT ON COLUMN fake_users.bot_farewell_messages IS 'Per-bot goodbye variants, one per line, used only if the closing LLM call fails';
COMMENT ON COLUMN fake_users.bot_ignore_chance IS 'Per-bot chance (%) of ignoring a new conversation for good (NULL = use bot_ignore_chance setting)';
COMMENT ON COLUMN fake_users.bot_llm_provider IS 'Per-bot LLM provider override, e.g. deepseek or openai (NULL = use bot_llm_provider setting)';
COMMENT ON COLUMN fake_users.bot_llm_model IS 'Per-bot model override (NULL = use bot_llm_model setting)';
COMMENT ON COLUMN fake_users.bot_reply_language IS 'Script the bot writes in: auto (mirror the peer), greek, greeklish or english';
COMMENT ON COLUMN fake_users.bot_self_facts IS 'Canon of stable self-facts the bot has committed to (appearance, personal details), injected into every reply so it stays consistent across conversations.';
COMMENT ON COLUMN tracks.excluded_from_stats IS 'When true, the track is hidden from stats (e.g. jingles) but still appears in the play log.';
COMMENT ON COLUMN tracks.enriched_at IS 'Timestamp of last external metadata enrichment; NULL means pending.';
COMMENT ON COLUMN bot_threads.messages_sent IS 'Messages the BOT authored in this thread (admin impersonation replies are not counted)';
COMMENT ON COLUMN bot_threads.is_taken_over IS 'TRUE once an admin impersonated this fake user in this thread - the bot stays silent';
COMMENT ON COLUMN bot_threads.farewell_sent_at IS 'When the closing message was sent; the bot never replies again after this';
COMMENT ON COLUMN bot_threads.is_ignored IS 'TRUE when the bot decided to ignore this conversation from the start - it never replies in it';
COMMENT ON COLUMN bot_threads.ignore_decided_at IS 'When the ignore/reply decision was taken, so a burst of messages does not re-roll it';
COMMENT ON COLUMN bot_threads.insult_count IS 'Abusive messages received from this peer in this conversation';
COMMENT ON COLUMN bot_threads.blocked_at IS 'When the bot blocked this peer over repeated abuse (a real dm_blocks row, same as the DM block button)';
COMMENT ON COLUMN bot_threads.summary IS 'Rolling summary of the messages that fell out of the history window';
COMMENT ON COLUMN bot_threads.summary_upto_id IS 'Highest private_messages.id covered by the summary';
COMMENT ON COLUMN bot_llm_log.finish_reason IS 'stop = complete; length = the token budget ran out and the reply is truncated';
COMMENT ON COLUMN bot_llm_log.usage IS 'Token usage as reported by the provider, including reasoning_tokens';
COMMENT ON COLUMN bot_llm_log.cost IS 'Cost of this call from the bot_llm_prices unit prices, priced when it was made (NULL = no price configured for the model)';
COMMENT ON COLUMN scheduled_tasks.task IS 'Task name from Scheduler::TASKS';
COMMENT ON COLUMN scheduled_tasks.last_status IS 'ok or failed - a failed task is retried on the next interval, it does not stop the worker';
COMMENT ON VIEW user_stats IS 'Convenient view of user activity statistics (historical participation data)';
COMMENT ON INDEX idx_messages_active_recent IS 'Optimizes message history queries';
COMMENT ON INDEX idx_user_activity_username_stats IS 'Covering index for user activity stats queries';
COMMENT ON INDEX idx_sessions_validation IS 'Optimizes session validation queries (session_id + username)';
COMMENT ON INDEX idx_banned_ips_banned_until IS 'Speeds up active ban checks';
COMMENT ON INDEX idx_banned_ips_permanent IS 'Partial index for permanent IP bans (banned_until IS NULL)';
COMMENT ON INDEX idx_settings_key_value IS 'Covering index for settings lookups';
COMMENT ON INDEX idx_users_active_display IS 'Covering index for active user queries with display names';
COMMENT ON INDEX idx_pm_conversation IS 'Optimizes private message conversation queries by covering common WHERE clauses';
COMMENT ON INDEX idx_pm_created_at IS 'Optimizes private message conversation ordering';
COMMENT ON FUNCTION public.aggregate_hourly_stats(timestamp with time zone) IS 'Aggregate statistics for a specific hour from raw data and snapshots';
COMMENT ON FUNCTION public.aggregate_daily_stats(date) IS 'Aggregate daily statistics from hourly stats';
COMMENT ON FUNCTION public.aggregate_weekly_stats(date) IS 'Aggregate weekly statistics from daily stats';
COMMENT ON FUNCTION public.aggregate_monthly_stats(date) IS 'Aggregate monthly statistics from daily stats';
COMMENT ON FUNCTION public.aggregate_yearly_stats(integer) IS 'Aggregate yearly statistics from daily stats';
COMMENT ON FUNCTION public.cleanup_inactive_sessions() IS 'Removes sessions inactive for more than 5 minutes (heartbeat timeout)';
COMMENT ON FUNCTION public.update_user_stats() IS 'Automatically updates user_activity table when messages are posted (tracks participation history)';
COMMENT ON FUNCTION public.aggregate_hourly_stats(timestamp without time zone) IS 'Aggregate statistics for a specific hour from raw data and snapshots';
COMMENT ON FUNCTION public.cleanup_old_snapshots() IS 'Remove snapshots older than 30 days to keep table size manageable';
COMMENT ON FUNCTION public.mark_notification_read(integer,character varying) IS 'Mark notification as read for specific admin';
COMMENT ON FUNCTION public.mark_all_notifications_read(character varying) IS 'Mark all unread notifications as read for specific admin';
COMMENT ON FUNCTION public.get_unread_notification_count(character varying) IS 'Get count of unread notifications for specific admin';
COMMENT ON FUNCTION public.cleanup_old_notifications() IS 'Delete notifications older than 30 days that have been read by at least one admin';
COMMENT ON FUNCTION public.create_fake_user_dm_notification(character varying,character varying,text,integer) IS 'Create or update notification when user sends DM to fake user - prevents duplicates until admin reads';
SQL);

        // --- seed data ---
        $this->DB()->statement(<<<'SQL'
SET session_replication_role = replica;
INSERT INTO public.banned_nicknames VALUES (1, 'moderator', 'Reserved for moderators', '2026-07-27 10:19:01.16217+03', 'system');
INSERT INTO public.banned_nicknames VALUES (2, 'system', 'Reserved for system messages', '2026-07-27 10:19:01.16217+03', 'system');
INSERT INTO public.users VALUES (1, 'admin', '$2y$10$ZUCvW9SmSpOUwPtWC.XzL.mA0piFBy.DM8TKPHvkWdd0CsG121vCC', 'root', NULL, NULL, true, '2026-07-27 10:19:01.263537+03', '2026-07-27 10:19:01.263537+03', NULL, NULL);
INSERT INTO public.messages VALUES (1, 'welcome_msg', 'System', 'Welcome to RadioChatBox! Be respectful and have fun! 🎙️', '127.0.0.1', '2026-07-27 10:19:01.168215+03', false, NULL, NULL, NULL, NULL, NULL);
INSERT INTO public.settings VALUES (1, 'rate_limit_messages', '10', '2026-07-27 10:19:01.155101+03', '2026-07-27 10:19:01.155101+03');
INSERT INTO public.settings VALUES (2, 'rate_limit_window', '60', '2026-07-27 10:19:01.155101+03', '2026-07-27 10:19:01.155101+03');
INSERT INTO public.settings VALUES (3, 'color_scheme', 'dark', '2026-07-27 10:19:01.155101+03', '2026-07-27 10:19:01.155101+03');
INSERT INTO public.settings VALUES (4, 'page_title', 'RadioChatBox', '2026-07-27 10:19:01.155101+03', '2026-07-27 10:19:01.155101+03');
INSERT INTO public.settings VALUES (5, 'require_profile', 'false', '2026-07-27 10:19:01.155101+03', '2026-07-27 10:19:01.155101+03');
INSERT INTO public.settings VALUES (6, 'chat_mode', 'both', '2026-07-27 10:19:01.155101+03', '2026-07-27 10:19:01.155101+03');
INSERT INTO public.settings VALUES (7, 'allow_photo_uploads', 'true', '2026-07-27 10:19:01.155101+03', '2026-07-27 10:19:01.155101+03');
INSERT INTO public.settings VALUES (8, 'max_photo_size_mb', '5', '2026-07-27 10:19:01.155101+03', '2026-07-27 10:19:01.155101+03');
INSERT INTO public.settings VALUES (9, 'minimum_users', '0', '2026-07-27 10:19:01.155101+03', '2026-07-27 10:19:01.155101+03');
INSERT INTO public.settings VALUES (10, 'site_title', 'RadioChatBox - Real-time Chat', '2026-07-27 10:19:01.155101+03', '2026-07-27 10:19:01.155101+03');
INSERT INTO public.settings VALUES (11, 'site_description', 'Connect with listeners in real-time during your radio show', '2026-07-27 10:19:01.155101+03', '2026-07-27 10:19:01.155101+03');
INSERT INTO public.settings VALUES (12, 'site_keywords', 'radio, chat, live, real-time, broadcast', '2026-07-27 10:19:01.155101+03', '2026-07-27 10:19:01.155101+03');
INSERT INTO public.settings VALUES (13, 'meta_author', '', '2026-07-27 10:19:01.155101+03', '2026-07-27 10:19:01.155101+03');
INSERT INTO public.settings VALUES (14, 'meta_og_image', '', '2026-07-27 10:19:01.155101+03', '2026-07-27 10:19:01.155101+03');
INSERT INTO public.settings VALUES (15, 'meta_og_type', 'website', '2026-07-27 10:19:01.155101+03', '2026-07-27 10:19:01.155101+03');
INSERT INTO public.settings VALUES (16, 'favicon_url', '', '2026-07-27 10:19:01.155101+03', '2026-07-27 10:19:01.155101+03');
INSERT INTO public.settings VALUES (17, 'logo_url', '', '2026-07-27 10:19:01.155101+03', '2026-07-27 10:19:01.155101+03');
INSERT INTO public.settings VALUES (18, 'brand_color', '#007bff', '2026-07-27 10:19:01.155101+03', '2026-07-27 10:19:01.155101+03');
INSERT INTO public.settings VALUES (19, 'brand_name', 'RadioChatBox', '2026-07-27 10:19:01.155101+03', '2026-07-27 10:19:01.155101+03');
INSERT INTO public.settings VALUES (20, 'header_scripts', '', '2026-07-27 10:19:01.155101+03', '2026-07-27 10:19:01.155101+03');
INSERT INTO public.settings VALUES (21, 'body_scripts', '', '2026-07-27 10:19:01.155101+03', '2026-07-27 10:19:01.155101+03');
INSERT INTO public.settings VALUES (22, 'analytics_enabled', 'false', '2026-07-27 10:19:01.155101+03', '2026-07-27 10:19:01.155101+03');
INSERT INTO public.settings VALUES (23, 'analytics_provider', '', '2026-07-27 10:19:01.155101+03', '2026-07-27 10:19:01.155101+03');
INSERT INTO public.settings VALUES (24, 'analytics_tracking_id', '', '2026-07-27 10:19:01.155101+03', '2026-07-27 10:19:01.155101+03');
INSERT INTO public.settings VALUES (25, 'ads_enabled', 'false', '2026-07-27 10:19:01.155101+03', '2026-07-27 10:19:01.155101+03');
INSERT INTO public.settings VALUES (26, 'ads_main_top', '', '2026-07-27 10:19:01.155101+03', '2026-07-27 10:19:01.155101+03');
INSERT INTO public.settings VALUES (27, 'ads_main_bottom', '', '2026-07-27 10:19:01.155101+03', '2026-07-27 10:19:01.155101+03');
INSERT INTO public.settings VALUES (28, 'ads_chat_sidebar', '', '2026-07-27 10:19:01.155101+03', '2026-07-27 10:19:01.155101+03');
INSERT INTO public.settings VALUES (29, 'ads_refresh_interval', '30', '2026-07-27 10:19:01.155101+03', '2026-07-27 10:19:01.155101+03');
INSERT INTO public.settings VALUES (30, 'ads_refresh_enabled', 'false', '2026-07-27 10:19:01.155101+03', '2026-07-27 10:19:01.155101+03');
INSERT INTO public.settings VALUES (31, 'radio_status_url', '', '2026-07-27 10:19:01.155101+03', '2026-07-27 10:19:01.155101+03');
INSERT INTO public.settings VALUES (32, 'bot_replies_enabled', 'false', '2026-07-27 10:19:04.094039+03', '2026-07-27 10:19:04.094039+03');
INSERT INTO public.settings VALUES (33, 'bot_llm_provider', 'deepseek', '2026-07-27 10:19:04.094039+03', '2026-07-27 10:19:04.094039+03');
INSERT INTO public.settings VALUES (34, 'bot_llm_api_key', '', '2026-07-27 10:19:04.094039+03', '2026-07-27 10:19:04.094039+03');
INSERT INTO public.settings VALUES (35, 'bot_llm_base_url', 'https://api.deepseek.com', '2026-07-27 10:19:04.094039+03', '2026-07-27 10:19:04.094039+03');
INSERT INTO public.settings VALUES (36, 'bot_openai_api_key', '', '2026-07-27 10:19:04.094039+03', '2026-07-27 10:19:04.094039+03');
INSERT INTO public.settings VALUES (37, 'bot_openai_base_url', 'https://api.openai.com/v1', '2026-07-27 10:19:04.094039+03', '2026-07-27 10:19:04.094039+03');
INSERT INTO public.settings VALUES (38, 'bot_openai_model', 'gpt-5.4-mini', '2026-07-27 10:19:04.094039+03', '2026-07-27 10:19:04.094039+03');
INSERT INTO public.settings VALUES (39, 'bot_openai_temperature', '1.0', '2026-07-27 10:19:04.094039+03', '2026-07-27 10:19:04.094039+03');
INSERT INTO public.settings VALUES (40, 'bot_openai_max_tokens', '1000', '2026-07-27 10:19:04.094039+03', '2026-07-27 10:19:04.094039+03');
INSERT INTO public.settings VALUES (41, 'bot_openai_admin_key', '', '2026-07-27 10:19:04.094039+03', '2026-07-27 10:19:04.094039+03');
INSERT INTO public.settings VALUES (42, 'bot_llm_model', 'deepseek-v4-flash', '2026-07-27 10:19:04.094039+03', '2026-07-27 10:19:04.094039+03');
INSERT INTO public.settings VALUES (43, 'bot_llm_temperature', '1.0', '2026-07-27 10:19:04.094039+03', '2026-07-27 10:19:04.094039+03');
INSERT INTO public.settings VALUES (44, 'bot_llm_max_tokens', '1000', '2026-07-27 10:19:04.094039+03', '2026-07-27 10:19:04.094039+03');
INSERT INTO public.settings VALUES (45, 'bot_llm_reasoning', 'false', '2026-07-27 10:19:04.094039+03', '2026-07-27 10:19:04.094039+03');
INSERT INTO public.settings VALUES (46, 'bot_llm_log_enabled', 'true', '2026-07-27 10:19:04.094039+03', '2026-07-27 10:19:04.094039+03');
INSERT INTO public.settings VALUES (47, 'bot_llm_prices', '', '2026-07-27 10:19:04.094039+03', '2026-07-27 10:19:04.094039+03');
INSERT INTO public.settings VALUES (48, 'bot_llm_currency', 'USD', '2026-07-27 10:19:04.094039+03', '2026-07-27 10:19:04.094039+03');
INSERT INTO public.settings VALUES (49, 'bot_llm_log_retention_days', '7', '2026-07-27 10:19:04.094039+03', '2026-07-27 10:19:04.094039+03');
INSERT INTO public.settings VALUES (50, 'bot_max_messages_per_thread', '4', '2026-07-27 10:19:04.094039+03', '2026-07-27 10:19:04.094039+03');
INSERT INTO public.settings VALUES (51, 'bot_ignore_chance', '30', '2026-07-27 10:19:04.094039+03', '2026-07-27 10:19:04.094039+03');
INSERT INTO public.settings VALUES (52, 'bot_insult_block_threshold', '3', '2026-07-27 10:19:04.094039+03', '2026-07-27 10:19:04.094039+03');
INSERT INTO public.settings VALUES (53, 'bot_history_limit', '20', '2026-07-27 10:19:04.094039+03', '2026-07-27 10:19:04.094039+03');
INSERT INTO public.settings VALUES (54, 'bot_summary_enabled', 'true', '2026-07-27 10:19:04.094039+03', '2026-07-27 10:19:04.094039+03');
INSERT INTO public.settings VALUES (55, 'bot_summary_prompt', '', '2026-07-27 10:19:04.094039+03', '2026-07-27 10:19:04.094039+03');
INSERT INTO public.settings VALUES (56, 'bot_context_prompt', '', '2026-07-27 10:19:04.094039+03', '2026-07-27 10:19:04.094039+03');
INSERT INTO public.settings VALUES (57, 'bot_farewell_prompt', '', '2026-07-27 10:19:04.094039+03', '2026-07-27 10:19:04.094039+03');
INSERT INTO public.settings VALUES (58, 'bot_farewell_messages', '', '2026-07-27 10:19:04.094039+03', '2026-07-27 10:19:04.094039+03');
INSERT INTO public.settings VALUES (59, 'bot_typing_seconds_per_word', '1.5', '2026-07-27 10:19:04.094039+03', '2026-07-27 10:19:04.094039+03');
INSERT INTO public.settings VALUES (60, 'bot_typing_min_delay', '2', '2026-07-27 10:19:04.094039+03', '2026-07-27 10:19:04.094039+03');
INSERT INTO public.settings VALUES (61, 'bot_typing_max_delay', '45', '2026-07-27 10:19:04.094039+03', '2026-07-27 10:19:04.094039+03');
INSERT INTO public.settings VALUES (62, 'bot_read_delay_min', '2', '2026-07-27 10:19:04.094039+03', '2026-07-27 10:19:04.094039+03');
INSERT INTO public.settings VALUES (63, 'bot_read_delay_max', '8', '2026-07-27 10:19:04.094039+03', '2026-07-27 10:19:04.094039+03');
INSERT INTO public.settings VALUES (64, 'track_poll_seconds', '30', '2026-07-27 10:19:04.277781+03', '2026-07-27 10:19:04.277781+03');
INSERT INTO public.url_blacklist VALUES (1, 'bit.ly', 'URL shortener - often used for spam', 'system', '2026-07-27 10:19:01.165139+03');
INSERT INTO public.url_blacklist VALUES (2, 'tinyurl.com', 'URL shortener - often used for spam', 'system', '2026-07-27 10:19:01.165139+03');
INSERT INTO public.url_blacklist VALUES (3, 'goo.gl', 'URL shortener - often used for spam', 'system', '2026-07-27 10:19:01.165139+03');
INSERT INTO public.url_whitelist VALUES (1, 'youtube.com', 'YouTube video platform', 'system', '2026-07-27 10:19:01.781011');
INSERT INTO public.url_whitelist VALUES (2, 'youtu.be', 'YouTube short links', 'system', '2026-07-27 10:19:01.781011');
INSERT INTO public.url_whitelist VALUES (3, 'twitter.com', 'Twitter social media', 'system', '2026-07-27 10:19:01.781011');
INSERT INTO public.url_whitelist VALUES (4, 'x.com', 'X (Twitter) social media', 'system', '2026-07-27 10:19:01.781011');
INSERT INTO public.url_whitelist VALUES (5, 'facebook.com', 'Facebook social media', 'system', '2026-07-27 10:19:01.781011');
INSERT INTO public.url_whitelist VALUES (6, 'instagram.com', 'Instagram social media', 'system', '2026-07-27 10:19:01.781011');
INSERT INTO public.url_whitelist VALUES (7, 'tiktok.com', 'TikTok video platform', 'system', '2026-07-27 10:19:01.781011');
INSERT INTO public.url_whitelist VALUES (8, 'spotify.com', 'Spotify music streaming', 'system', '2026-07-27 10:19:01.781011');
INSERT INTO public.url_whitelist VALUES (9, 'soundcloud.com', 'SoundCloud music platform', 'system', '2026-07-27 10:19:01.781011');
INSERT INTO public.url_whitelist VALUES (10, 'twitch.tv', 'Twitch streaming platform', 'system', '2026-07-27 10:19:01.781011');
INSERT INTO public.user_activity VALUES (1, 'System', NULL, '127.0.0.1', '2026-07-27 10:19:01.168215+03', '2026-07-27 10:19:01.168215+03', 1, false, false, NULL);
SELECT pg_catalog.setval('public.admin_notification_reads_id_seq', 1, false);
SELECT pg_catalog.setval('public.admin_notifications_id_seq', 1, false);
SELECT pg_catalog.setval('public.albums_id_seq', 1, false);
SELECT pg_catalog.setval('public.artists_id_seq', 1, false);
SELECT pg_catalog.setval('public.attachments_id_seq', 1, false);
SELECT pg_catalog.setval('public.banned_ips_id_seq', 1, false);
SELECT pg_catalog.setval('public.banned_nicknames_id_seq', 2, true);
SELECT pg_catalog.setval('public.bot_llm_balance_id_seq', 1, false);
SELECT pg_catalog.setval('public.bot_llm_log_id_seq', 1, false);
SELECT pg_catalog.setval('public.bot_threads_id_seq', 1, false);
SELECT pg_catalog.setval('public.dm_blocks_id_seq', 1, false);
SELECT pg_catalog.setval('public.fake_users_id_seq', 1, false);
SELECT pg_catalog.setval('public.message_reactions_id_seq', 1, false);
SELECT pg_catalog.setval('public.messages_id_seq', 1, true);
SELECT pg_catalog.setval('public.private_messages_id_seq', 1, false);
SELECT pg_catalog.setval('public.sessions_id_seq', 1, false);
SELECT pg_catalog.setval('public.settings_id_seq', 64, true);
SELECT pg_catalog.setval('public.stats_daily_id_seq', 1, false);
SELECT pg_catalog.setval('public.stats_hourly_id_seq', 1, false);
SELECT pg_catalog.setval('public.stats_monthly_id_seq', 1, false);
SELECT pg_catalog.setval('public.stats_snapshots_id_seq', 1, false);
SELECT pg_catalog.setval('public.stats_weekly_id_seq', 1, false);
SELECT pg_catalog.setval('public.stats_yearly_id_seq', 1, false);
SELECT pg_catalog.setval('public.track_plays_id_seq', 1, false);
SELECT pg_catalog.setval('public.tracks_id_seq', 1, false);
SELECT pg_catalog.setval('public.url_blacklist_id_seq', 3, true);
SELECT pg_catalog.setval('public.url_whitelist_id_seq', 10, true);
SELECT pg_catalog.setval('public.user_activity_id_seq', 1, true);
SELECT pg_catalog.setval('public.user_profiles_id_seq', 1, false);
SELECT pg_catalog.setval('public.users_id_seq', 1, true);
SET session_replication_role = DEFAULT;
SQL);
    }

    public function down(): void
    {
        // Baseline migration: no automated rollback.
    }
}
