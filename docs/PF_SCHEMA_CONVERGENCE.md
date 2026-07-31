# PF Schema Convergence — Analysis & Plan

**Status:** analysis / not started
**Goal:** make RCB a full-fledged Pramnos Framework app on a single unified schema — running the **whole** framework migration set — so we gain native auth (passkeys/WebAuthn, TOTP 2FA, brute-force lockout, token rotation), the framework **Settings** infrastructure, and the rest of the framework surface (queue, broadcasting, notifications, OAuth2/RBAC) as it lands.

**Decisions taken (2026-07-31):**
1. **Whole-schema, not cherry-pick.** Run all framework migrations (`core` + `auth` + `authserver` + `messaging` + `queue` + ungated dirs), not a surgical dependency-pool subset. Cleaner end state, no fragile slug-dependency wiring, full framework future-proofing — at the cost of a larger app-code blast radius.
2. **Adopt the framework `settings` infrastructure** — RCB's `settings` converges into the framework `settings` table, not mere coexistence.

**Consequence:** all four name collisions are now in play and must be handled — `users` (converge), `settings` (converge), `messages` (rename), `sessions` (rename).

---

## 1. Strategy: run the whole framework schema

Enable via `app/app.php`:
- `'features' => ['auth', 'authserver', 'messaging', 'queue']` (as urbanwater does; `core` is always-on and now **wanted**, since it brings `settings`/`sessions`).
- `'migrations' => ['paths' => [__DIR__.'/Migrations'], 'framework' => true]` (flip `framework` from `false` → `true`).
- Keep `migration_cutoff` so the RCB baseline still applies first.

> **Rejected alternative (documented for the record):** the *dependency-pool* route — keeping `framework => false` and declaring `dependencies` on individual framework slugs — would have avoided the `settings`/`sessions`/`messages` collisions entirely (only `users` collides), because no `auth`/`authserver` migration depends on any `core`/`messaging` slug. We are **not** taking this route, per decision #1: we want the full schema, including framework `settings` and `messaging`.

Ordering note: framework `create_users_table`, `create_settings_table`, `create_sessions_table`, `messaging/create_messages_table` all guard with `hasTable()` → **first creator wins, silently**. So RCB's colliding tables must be **renamed/cleared before** the framework migrations run, otherwise the framework skips creating its versions and its runtime code hits RCB's incompatible columns ("column does not exist" in production, green CI). The rename/converge steps therefore run in the **same batch, ordered before** the framework set (via `migration_cutoff` / an RCB pre-migration).

---

## 2. The four collisions — resolution + blast radius

### 2.1 `users` — CONVERGE (data migration)

| Concern | RCB `users` | Framework `users` |
|---|---|---|
| PK | `id` **SERIAL (int4)** | `userid` **BIGINT**, seq starts at 2 (userid 1 = Guest, reserved) |
| password | `password_hash` varchar(255) — **plain bcrypt, no pepper** (§Q1) | `password` varchar(100), peppered `password_hash($pwd . md5($salt.$userid))`; auto-upgrades legacy md5 only |
| role/type | `role` enum {root, administrator, moderator, simple_user} | `usertype` TINYINT |
| email | varchar(255) NULL, partial-unique | varchar(150) NOT NULL default '' |
| name | `display_name` varchar(100) | `firstname`/`lastname`; no `display_name` → goes to `userdetails` EAV |
| active | `is_active` boolean | `active` + `validated` TINYINT |
| timestamps | `created_at`/`updated_at`/`last_login` **timestamptz** | `regdate`/`modified`/`lastlogin` **INTEGER epoch** |
| other | `created_by` self-FK | +~15 cols (`language,timezone,dateformat,sex,birthdate,photo,phone,fax,mobile,vat,website,fbauth,regcompletion,lasttermsagreed`) + `userdetails` companion |

**Inbound FKs to `users.id` to repoint (int → bigint, new ids):** `dm_blocks.blocker_user_id`/`blocked_user_id` (CASCADE), `messages.user_id` (SET NULL, **two duplicate FKs**), `private_messages.from_user_id`/`to_user_id` (SET NULL), `sessions.user_id` (SET NULL), `user_activity.user_id` (SET NULL), `users.created_by` (self).

**Seed-ID clash:** RCB `id=1` (admin/root) vs framework `userid=1` (Guest). Remap admin off 1 and cascade the new id through all inbound FKs.

**Password blocker — CONFIRMED (§Q1).** RCB stores bcrypt of the *plain* password (`PASSWORD_DEFAULT`, cost 10, no pepper), verified via `password_verify()` at `src/Services/UserService.php:394`; created at `UserService.php:89,158` and `src/AdminAuth.php:335`. The framework verifier bcrypt-checks the *peppered* string, so **every existing RCB login would fail**, and the md5-only auto-upgrade won't rescue bcrypt-of-plain.
→ **Resolution:** register a custom `AuthDriverInterface` in the framework auth chain that (a) verifies the plain-bcrypt RCB hash, and (b) on success rehashes to the framework peppered scheme (rehash-on-login), so existing users migrate transparently over time. No forced reset. This driver is a Phase-A prerequisite.

### 2.2 `settings` — CONVERGE (adopt framework infra)

| RCB `settings` | Framework `settings` |
|---|---|
| `id, setting_key, setting_value, created_at, updated_at`; unique(setting_key); **64 seed rows**; Redis `FlatCache` | `setting_id, setting, value, delete(tinyint)`; unique(setting) |

**Adoption approach (keeps blast radius small):** RCB's `SettingsService` (`src/Services/SettingsService.php`) is the single facade — **27 distinct files** reach settings only through it (or via `ChatService::getSetting/getSettings`). So we **keep `SettingsService` as the app API** (its derived getters `getSeoMeta`/`getBranding`/`getAdSettings`/…, the `ADMIN_EDITABLE` whitelist, `NUMERIC_BOUNDS`, Redis cache) and only **repoint its internal SQL** to the framework columns (`setting`/`value`, handle the reserved-word `delete` column), optionally delegating to `Pramnos\Application\Settings`. Plus a one-time data migration mapping the 64 rows `setting_key→setting`, `setting_value→value`.
→ Blast radius: **~1 file (`SettingsService`) + 1 data migration**, not 27 call sites. Internal DB access to rewrite: `SettingsService.php` lines ~172-175, 223-232, 295-304, 399, 416-425.

### 2.3 `messages` — RENAME (`messages` → `chat_messages`)

RCB public chat feed vs framework internal PM. RCB table renamed so framework `messaging/create_messages_table` creates its own `messages`.
**Blast radius (§Q3):** **9 code files, ~21 SQL refs** — `ChatService.php` (hotspot: 162-163, 228-229, 393, 441, 1186, 1221, 1258, 1263), `AdminUsersController`, `AdminSystemController`, `MessageActionController`, `AdminModerationController`, `MaintenanceController`, `ReactionService`, `CleanupService`, `StatsService`.
**DB objects to update on rename:** view `recent_messages`, view `user_stats`, function `update_user_stats()` + trigger `trigger_update_user_stats`, FK `fk_message_reactions_message` (message_reactions.message_id → messages.message_id), and aggregate functions `aggregate_hourly/daily/weekly/monthly/yearly_stats` (several read `FROM messages`).

### 2.4 `sessions` — RENAME (`sessions` → `presence_sessions`)

RCB presence/heartbeat vs framework visitor-tracking. RCB deliberately does **not** run `Application::init()`/`SessionTrackingMiddleware`, but the framework `core` sessions table will now exist (and we can adopt its tracking later if wanted).
**Blast radius (§Q3):** **11 code files, ~22 SQL refs** — `ChatService.php` (hotspot: 551, 610, 687, 703, 770, 798, 855, 893, 916, 1007, 1281, 1370), `AuthController` (75, INSERT…ON CONFLICT), `AdminUsersController`, `AdminImpersonationController`, `AdminSystemController`, `AdminModerationController`, `MessageActionController`, `ProfileController`, `StatsService`, `CleanupService`, `BotService`.
**DB objects:** function `cleanup_inactive_sessions()` (`DELETE FROM sessions`).

---

## 3. Full catalog

### 3.1 Framework tables RCB gains (whole schema, created fresh)
- **core:** `settings` (adopted, §2.2), `sessions` (framework visitor-tracking, alongside renamed `presence_sessions`), `pramnos.framework_policies`, `pramnos` schema.
- **auth:** `users` (converged), `userdetails`, `userlog`, `usernotes`, `usertokens`, `urls`, `tokenactions`.
- **authserver:** `loginlockouts`, `user_twofactor`, `twofactor_setup/attempts`, `passkey_credentials`, RBAC (`roles`, `permissions`, `user_roles`, …), OAuth2 (`public.applications`, `oauth2_*`, device flow), GDPR tables, audit logs, views + PG functions.
- **messaging:** `mails`, `mailtemplates`, `messages` (framework PM), `massmessages`, `massmessagerecipients`.
- **queue:** `queueitems`, `delayed_jobs`.
- **ungated dirs (always run):** `applications.*`, `broadcasting` (`broadcast_events`), `notifications` (`notifications`).

### 3.2 RCB-only tables (unchanged, kept as-is)
`albums, artists, tracks, track_plays, attachments, banned_ips, banned_nicknames, bot_llm_balance, bot_llm_log, bot_threads, dm_blocks, fake_users, message_reactions, private_messages, scheduled_tasks, stats_*, url_blacklist, url_whitelist, user_activity, user_profiles, admin_notifications, admin_notification_reads` + the **renamed** `chat_messages`, `presence_sessions`.

### 3.3 Conceptual overlaps NOT converged (framework version coexists, RCB keeps its own)
| RCB | Framework | Decision |
|---|---|---|
| `private_messages` + `attachments` | `messages` (PM), `mails` | Keep RCB's; framework versions exist but unused for now. |
| `admin_notifications` | `notifications` | Keep RCB's. |
| `scheduled_tasks` | `queueitems`/`delayed_jobs` | Keep RCB's; revisit under Phase 8 queue infra. |

---

## 4. Migration plan (whole-schema, 3-step batch)

All steps below run as RCB app migrations ordered **before** the framework set in the same run (so the `hasTable()` guards see clean ground):

**Step 1 — rename & clear RCB collisions**
- Rename `messages` → `chat_messages`; recreate/redirect its view/trigger/function/FK and aggregate functions (§2.3). Update the 9 code files.
- Rename `sessions` → `presence_sessions`; redirect `cleanup_inactive_sessions()` (§2.4). Update the 11 code files.
- Prep `users` and `settings` for convergence: rename `users` → `users_legacy`; rename `settings` → `settings_legacy` (retain data for Step 3).

**Step 2 — run the whole framework schema**
- Flip `app/app.php` `features` + `migrations.framework => true` (§1). Framework creates `users`, `settings`, `sessions`, `messages` (PM), plus the full auth/authserver/queue/messaging surface.

**Step 3 — custom convergence migrations (data)**
- `users_legacy` → `users`: remap admin off id 1; allocate BIGINT `userid`s; column mapping (`role→usertype` table, timestamptz→epoch, `display_name`/`created_by`→`userdetails`, email NOT NULL); **repoint every inbound FK** (§2.1) to the new `userid`.
- `settings_legacy` (64 rows) → framework `settings` (`setting_key→setting`, `setting_value→value`, set `delete`); repoint `SettingsService` internals (§2.2).
- Register the **compatibility `AuthDriverInterface`** (plain-bcrypt verify + rehash-on-login) so existing users keep logging in (§2.1).

**Suggested execution phasing** (de-risk by shipping value early):
- **Phase A:** Steps 1–2 for the non-`users` parts + the auth infra tables, driving auth against a `users`↔`users_legacy` adapter or an early converge. Delivers 2FA + lockout + passkeys.
- **Phase B:** full `users` + `settings` data convergence (Step 3) once Phase A is stable.

---

## 5. Open decisions / prerequisites

| # | Item | Status |
|---|---|---|
| 1 | RCB password scheme | **Resolved:** plain bcrypt, no pepper → compatibility `AuthDriverInterface` (verify-plain + rehash-on-login). No forced reset. See §6. |
| 2 | Seed-ID remap (admin off userid 1) | **Resolved (feasible).** Cascade new `userid` through inbound FKs. |
| 3 | `role` → `usertype` mapping | **Mostly resolved:** ladder `root=99, administrator=90, moderator=50, simple_user=0` (root=99 per user). Model = **Hybrid** proposed (usertype for framework built-in gates + RBAC roles seeded for app authz, RBAC dormant until adopted). ⚠️ Awaiting explicit confirmation of the Hybrid vs usertype-only choice. |
| 4 | Settings adoption depth | **Resolved: full switch** to `Pramnos\Application\Settings` at all call sites — **conditional on** first improving the framework Settings cache so we lose no speed (§7.1). App-specific derived getters (`getSeoMeta`/`getBranding`/`getAdSettings`) become thin helpers over the framework API; admin whitelist/`NUMERIC_BOUNDS` validation stays app-side. |
| 5 | Session model | **Resolved: stateless tokens, reusing the framework's existing REST-API token infra** (`usertokens` + `loadByToken`, `UnifiedAuthMiddleware`). See §7.2. RCB keeps skipping `Application::init()`. |
| 6 | Auth scope | Operate the login-security subset (2FA/lockout/passkeys) first; OAuth2/RBAC tables exist but stay dormant until needed (ties to #3). |

---

## 6. Compatibility AuthDriver — design spec

RCB hashes are plain `bcrypt(password)` (no pepper); the framework expects `bcrypt(password . md5(securitySalt . userid))`. Both are `$2y$…` and **format-indistinguishable**, so a naive "rehash on every login" would loop forever. The driver must probe peppered-first, plain-second.

**Class:** `RadioChatBox\Auth\RcbAuthDriver implements \Pramnos\Auth\Drivers\AuthDriverInterface`
**Registered from** `bootstrap/pramnos.php` via `\Pramnos\Framework\Factory::getAuth()->setDriver(new RcbAuthDriver())` (single driver, no stock fallback — it replicates DatabaseAuthDriver's peppered path, so the framework path is redundant). Do **not** register an auth *addon* (addons short-circuit drivers entirely).

**`verify(string $username, string $password, bool $encryptedPassword = false): AuthResult`:**
1. Load user by `username OR email` from `#PREFIX#users` (`prepareQuery` + `#PREFIX#`, `%s`), `LIMIT 1`. Not found → `AuthResult::failure('User not found', 404)`.
2. Active-status gate (mirror DatabaseAuthDriver): `active==0`→failure(0), `==2`→failure(2), `==5`→failure(5). (Handle PG `'t'`/`'f'`.)
3. `$encryptedPassword === true` (cookie re-auth): direct compare `$password === $row['password']` → success/failure(400). No rehash.
4. **Peppered probe (already-migrated):** `$salt = Settings::getSetting('securitySalt'); if (password_verify($password . md5($salt . $uid), $row['password']))` → success, **no rehash**.
5. **Plain probe (legacy RCB):** `elseif (password_verify($password, $row['password']))` → rehash to framework scheme: `$new = password_hash($password . md5($salt . $uid), PASSWORD_DEFAULT); UPDATE #PREFIX#users SET password=%s WHERE userid=%d;` → success with `$new` as the `$auth` field.
6. Else `AuthResult::failure('Wrong Password!', 400)`.
7. Success returns `AuthResult::success($row['username'], (int)$uid, (string)$row['email'], $authHash, (int)$row['active'])` — pass the hash actually stored (new if rehashed, existing otherwise) as `$auth` so the login cookie/token matches.

**Idempotency:** step 4 short-circuits migrated users, so each legacy user rehashes exactly once (first post-migration login), thereafter takes the peppered path. Fleet effect: the `users_legacy→users` data migration copies the bcrypt hash verbatim into `password`; users transparently upgrade on next login; no forced reset.

**Note on `usertype`/authorization:** the driver only authenticates. Role→`usertype`/RBAC mapping happens in the `users` convergence migration (§2.1) and is a separate decision (§5 #3).

## 7. Framework improvements this convergence requires

Both are "friction → framework improvement" items — they benefit every PF app, not just RCB.

> **Implementation status (2026-07-31):** §7.1 and §7.2 are **done** in the framework (`PramnosFramework`), BC-validated against the existing framework unit/characterization suites. §6 (RcbAuthDriver) remains blocked on the `users` convergence (Phase B). Details of what shipped are inline below.

### 7.1 Settings cache (prerequisite for the full-switch decision, §5 #4) — ✅ DONE

Today `Pramnos\Application\Settings` (`PramnosFramework/src/Pramnos/Application/Settings.php`) is a per-request static array; on a miss it does a **per-key** DB read, cached via the SQL-result cache (category `settings`, 600s) — but **writes never invalidate that cache** (latent staleness bug, ≤600s). RCB's current `SettingsService` instead loads *all* settings once into Redis (300s) — much faster than N per-key reads. To not lose that when switching:

1. **Bulk load-all + cache:** add a method that runs a single `SELECT setting, value FROM #PREFIX#settings` and populates `self::$settings` in one shot, wrapped in the framework's existing `Cache::remember('all_settings', 300, fn() => …)` (`src/Pramnos/Cache/Cache.php:451`). Driver comes from `Settings::getSetting('cache')['method']` (Redis adapter + `getRedis()` already exist). Respect the connection-key short-circuit (Settings.php:164-174) and the `dbsettings===false` guard to avoid Cache→Settings recursion.
2. **Write invalidation (also fixes the existing bug):** in `setSetting()` and `deleteSetting()`, after the DB write, `Cache::getInstance(...)->delete('all_settings')` (and unset the in-memory key on delete). Mirrors the commented-out `sql_cache_flush_cache('usertokens')` pattern in `Token::save()`.

Result: `Settings::getSetting()` is one cached bulk read per 300s, write-correct — matching RCB's current speed, so the full switch loses nothing.

**Shipped in `Settings.php`:** `loadAllSettings()` (single `SELECT setting,value` via `query(..., true, 300, 'settings')` — served from Redis with **zero DB** on a warm cache; config-file values still win); a per-request `$bulkLoaded` guard (forced reads `$force=true` bypass it and hit the DB per-key); `invalidateCache()` called from `setSetting()`/`deleteSetting()` (`Database::cacheflush('settings')` + reset guard). **Bonus fix:** `deleteSetting()` no longer emits `DELETE ... LIMIT 1` (invalid on PostgreSQL) — it now uses the query builder; a null-database guard was added. New/updated tests in `SettingsTest.php`.

### 7.2 Session/API auth wiring (stateless, reuse framework infra — §5 #5) — ✅ token TTL DONE

The framework already has stateless token auth for its REST API; RCB reuses it rather than rolling its own. Token store = `#PREFIX#usertokens` (types `web_session`, `auth`/`access_token`), resolved by `User::loadByToken($token, 'auth', $setSessionApi=false)` — identity comes purely from the token row's `userid`, no `$_SESSION` needed. `loadByToken('auth')` matches `tokentype IN ('auth','access_token')`.

**Login mints an `auth` token (NOT `web_session`).** The canonical framework mechanism is `Pramnos\Auth\Controllers\ApiAccount::login()` → `issueToken()` (`src/Pramnos/Auth/Controllers/ApiAccount.php:45-133`), which — with **no PHP session** — does:
1. `Auth::verifyCredentials($username, $password, false, false)` (goes through our compatibility driver, §6).
2. `$jwt = JWT::encode([iss, aud, iat, nbf], $app->authenticationKey)` (HS256).
3. `$user->addToken('auth', $jwt, 'api_login')` → persists a `usertokens` row (`tokentype='auth'`, `status=1`).
4. Returns JSON `{status, access_token: $jwt, token_type: 'Bearer', user}`.

RCB's `AuthController::login` mirrors this (or reuses `ApiAccount` directly) instead of `createWebSessionToken`. The `web_session` token is the framework's *cookie*-login artifact — not what an SPA/API token client wants.

**Verify side (matches the mint):**
- **`UnifiedAuthMiddleware`** (`src/Pramnos/Http/Middleware/UnifiedAuthMiddleware.php`) — `Authorization: Bearer <jwt>`, HS256-decodes with `$app->authenticationKey`, then `loadByToken`. Best fit for first-party SPA. Register on RCB `/api` group: `'middleware' => [ new UnifiedAuthMiddleware(authKey: $app->authenticationKey, appNamespace: …) ]`. Session-independent.
- **`ApiAuthMiddleware`** — same JWT decode but reads the `accessToken`/`HTTP_ACCESSTOKEN` header and additionally requires an API key; use if we want API-key gating.
- **`OAuth2Middleware::validateAccessToken([scopes])`** — heavier standards path (`access_token`, RS256, client_id, scopes, `exp`) for when OAuth2 is adopted.

**✅ Token TTL — shipped.** `User::addToken()` gained an optional `$expires` (absolute epoch, null = never — BC preserved). It is written to the insert **only when non-null** — MySQL's `usertokens.expires` is `NOT NULL`, so passing null must fall back to the column default (caught by the real-DB suite via `./dockertest`; unit tests with mocked DB did not). `ApiAccount::issueToken()` gained a `tokenTtl()` seam (reads app config `auth.token_ttl`, default `0` = never-expires, BC preserved); when TTL > 0 it stamps both the JWT `exp` claim and `usertokens.expires`. RCB enables expiry by setting `auth.token_ttl` in `app/app.php`. Tests added in `ApiAccountControllerTest.php`.

**✅ usertokens cache invalidation — verified + fixed.** The auth path (`User::loadByToken`) uses the query builder (uncached), so a revoked/expired token can **never** authenticate from stale cache — no auth bypass. The only cached read is `Token::load($tokenid)` (3600s, `Token.php:221`); its write paths didn't evict it. Added `Database::cacheflush('usertokens')` to `Token::save()` and to `User::{deleteToken,clearTokens,deactivateToken,expireToken,cleanupAuthTokens,cleanupAllAuthTokens}` (mirrors the existing `userlist` flush on `User::save`).

## Appendix — source references

- Auth driver chain: `src/Pramnos/Auth/Drivers/{AuthDriverInterface,AuthResult,DatabaseAuthDriver}.php`; registration + order in `Auth.php` (`verifyCredentials`, `setDriver`/`addDriver`, `afterLogin`); rehash primitives `User::{setPassword,verifyPassword,save}`. `usertype` is a bare-integer admin ladder (0/50/80/90…), separate from RBAC (`PermissionResolver` over `authserver.user_roles`); Guest = userid 1 / usertype 0.

- Feature gating / whole-schema: `PramnosFramework/src/Pramnos/Application/{Application.php,FeatureRegistry.php}`, `src/Pramnos/Database/{MigrationLoader.php,MigrationRunner.php}`. `core` always-on: `FeatureRegistry::isEnabled()`.
- Auth services: `src/Pramnos/Auth/{Auth.php,LoginFlow.php,TwoFactorAuthService.php,Loginlockout.php,Passkey/PasskeyService.php,Drivers/DatabaseAuthDriver.php}`, `src/Pramnos/User/{User.php,Token.php}`.
- RCB password: `src/Services/UserService.php:89,158,377-394`, `src/AdminAuth.php:335`, `src/Controllers/AuthController.php:62`.
- RCB settings: `src/Services/SettingsService.php` (API + SQL at 172-175,223-232,295-304,399,416-425), facade `ChatService::getSetting/getSettings` (1477,1493); 27 consumer files.
- RCB renames: `messages`/`sessions` refs enumerated in §2.3/§2.4; all DB objects in `app/Migrations/2025_01_01_000001_create_schema.php`.
- RCB config: `app/app.php`, `app/settings/settings.php`, `radiochatbox.php`, `bootstrap/pramnos.php`. Prior context: `docs/ARCHITECTURE.md` §3.
