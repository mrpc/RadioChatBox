# Schema convergence — RadioChatBox ↔ PramnosFramework

Should RadioChatBox's database schema move closer to the framework's canonical schema? **Yes,
selectively** — but this is a distinct, higher-risk workstream from the infrastructure migration,
and one collision class is an outright production hazard if handled naively. This doc is the map.

> Read alongside [`03-integration-plan.md`](03-integration-plan.md) Phase 2 (migrations): the
> collisions below **change how we run the framework's built-in migrations** — see §5.

---

## 0. The one hazard to internalise first

**Every framework migration guards with `if ($schema->hasTable('<name>')) return;`.** So if a
table of the same name already exists in the RadioChatBox DB, the framework migration **silently
skips** — it does *not* error and does *not* alter. Then framework *runtime* code runs against
RadioChatBox's incompatible table and fails at runtime with "column does not exist".

That means: **running the framework's `core`/`auth`/`messaging` feature migrations against the
production DB would pass CI and the migration step, then break in production.** This is worse than
a hard failure. The rule that falls out of it (§5) governs Phase 2.

---

## 1. The four `public`-schema collisions (all incompatible)

| Table | RadioChatBox | Framework | Verdict |
|---|---|---|---|
| **`settings`** | `id` PK, `setting_key` UNIQUE, `setting_value`, timestamps | `setting_id` PK, `setting` (non-unique idx), `value`, `delete` flag | Incompatible column names/PK; neither a superset. Framework Settings code queries `setting`/`value` → errors against RCB's table. |
| **`users`** | `id SERIAL` PK, `password_hash`, `role user_role` ENUM, `display_name`, `is_active BOOLEAN` | `userid BIGINT` PK, `password`, `usertype TINYINT`, `active TINYINT`, ~25 profile cols, no `display_name` | Incompatible identity (PK name+type), role model, password/active columns. |
| **`sessions`** | `id SERIAL` PK, `session_id`, `username`, `ip_address`, `last_heartbeat`, `user_id` | `visitorid VARCHAR` PK, `uname`, `time INT` (unix), `host_addr`, `agent`, `userid`, `history` | Fundamentally different design (heartbeat online-list vs visitor tracking). |
| **`messages`** | **Public chat feed**: `message_id` UNIQUE, `username`, `message`, `ip_address`, `reply_to` | **Internal PM/notifications**: `messageid`, `type`, `subject`, `text`, `fromuserid`/`touserid` | **Highest severity** — same name, *different domain*. Framework messaging would read/write RCB's live chat feed. |

---

## 2. Framework tables that are safe to ADD (no collision)

These have no name clash with RadioChatBox and can be created immediately — they only *add*
capability:

- **`schemaversion`** — the migration tracker (created by the runner, not a migration file).
- **`queueitems`** — SQL job queue (RCB uses Redis; pure add — see [`02`](02-framework-improvements.md) §3).
- **`usertokens`** — framework auth-token store (session/api/access/refresh/auth_code/
  password_reset/email_verify + PKCE). RCB has **no** token table today → pure add, unlocks the
  framework's session/API/OAuth/reset flows.
- **`notifications`**, **`mails`/`mailtemplates`**, **`massmessages`/`massmessagerecipients`**.
- Everything in the **`authserver.*`** and **`applications.*`** Postgres schemas (RBAC roles,
  permissions, application configs) — namespaced, so they *cannot* collide with RCB's `public`.

---

## 3. What RadioChatBox could adopt (convergence targets, by effort)

| Target | Effort | Payoff |
|---|---|---|
| **Settings** → framework `settings` | Low–Med | Use the framework Settings subsystem natively. Migrate `setting_key→setting`, `setting_value→value`, set `delete=0` for permanent keys; move key-uniqueness into app logic (framework enforces it in PHP, not DB). |
| **Auth tokens** → `usertokens` | Low (pure add) | Gain framework session/API/OAuth/password-reset without touching existing tables. |
| **Users** → framework `users` (+ `userdetails`) | **High** | Native `User`/`Auth`/RBAC. But requires PK rename `id→userid` (int4→int8), `password_hash→password`, `role` enum → `usertype`+`authserver` roles, and **rewriting every FK**: `messages.user_id`, `private_messages.from/to_user_id`, `sessions.user_id`, `user_activity.user_id`, `dm_blocks.*`, `created_by`. `display_name` moves to a `userdetails`/extension column. |
| **Job queue** → `queueitems` | Med | Durable DB jobs instead of Redis — only if desired ([`02`](02-framework-improvements.md) §3). |
| **Sessions** → framework `sessions` | Med–High, low value | RCB's heartbeat/presence model differs; keep RCB's unless the framework presence model is wanted. |

---

## 4. What stays RadioChatBox-owned (no framework equivalent)

`fake_users`, `bot_threads`, `bot_llm_log`, `bot_llm_balance`, `bot_self_facts` (LLM bots);
`private_messages`, `dm_blocks`, `message_reactions`, `attachments`, `user_profiles`;
`banned_ips`, `banned_nicknames`, `url_blacklist`, `url_whitelist` (moderation);
`tracks`, `track_plays`, `artists`, `albums` (radio domain);
`stats_*` (RCB aggregation, distinct from `applications.application_stats`);
`admin_notifications`/`admin_notification_reads` (distinct from framework `notifications`);
`scheduled_tasks`; and the public-chat feed itself (see §5, it should be **renamed**).

---

## 5. Safe sequencing (this governs Phase 2)

The blanket rule: **never let the framework auto-migrate `settings`/`users`/`sessions`/`messages`
against the production DB until each name has been vacated or explicitly converged with a
data-migration.**

Concretely, for our migration plan:

- **Phase 2 (migrations infra), corrected:** adopt the tracked runner (`schemaversion`,
  `migration_cutoff` baselining of the 25 app SQL files) **but do NOT enable the framework's
  colliding feature migrations** (`core`/`auth`/`messaging`). Keep `app.php` `features` empty (or
  only non-colliding features). This is already how our `app/app.php` starts (`features => []`).
  Only run the **add-only** framework tables (§2) when we explicitly want them.

- **Convergence phases (separate track, after infra is stable):**
  1. **Add-only (zero risk):** enable `queue`, `usertokens`, `notifications`, `authserver.*`,
     `applications.*` — no collision, immediate capability.
  2. **Rename to clear collisions:** rename RCB `messages` → `chat_messages` (update app code +
     the `message_reactions`/`reply_to` references) so the framework messaging name is free. This
     is the single most important de-risking step, because the `messages` collision is a
     different-domain silent-skip.
  3. **Converge Settings:** create framework `settings`, copy data, repoint app code, drop old.
  4. **Converge Users (high effort):** create framework `users` (userid from 2), migrate rows +
     map roles to `authserver`, rewrite all FKs, keep `display_name` in `userdetails`.
  5. **Sessions/queue:** only if the framework models are wanted.

- **Never:** "drop the RCB tables so the framework can recreate them" — that destroys production
  chat history, accounts, and settings.

---

## 6. Recommendation

Converge **opportunistically and add-first**, not wholesale:

1. Take the **add-only** wins early (`usertokens`, `queue`, `authserver` RBAC, `notifications`) —
   they're pure capability with no collision.
2. Do the **`messages` → `chat_messages` rename** before *ever* enabling framework messaging —
   it's the one collision that fails silently and dangerously.
3. Converge **Settings** when touching that area (low effort, clean win).
4. Treat **Users** convergence as its own project with a data-migration + full FK rewrite +
   backup/restore drill — high value, high effort; not a prerequisite for the infra migration.
5. Keep the app-specific domain tables (§4) exactly as they are.

The infrastructure migration (Phases 0–7 in [`03`](03-integration-plan.md)) does **not** depend
on schema convergence — the framework runs fine against RCB's existing tables **as long as the
colliding feature migrations stay disabled**. Convergence is an independent, incremental
enhancement track that this document scopes.
