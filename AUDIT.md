# Synditracker WordPress Plugin — QA Audit & Improvement Guide

**Audit Date:** February 2026  
**Scope:** Synditracker Core (Hub) + Synditracker Agent  
**Focus:** Enterprise/SaaS readiness, WordPress coding standards, security, performance, modularity.

---

## Executive Summary

The Synditracker system is a two-part syndication monitoring solution with a clear separation between Hub and Agent. One **critical runtime bug** was found and fixed (REST API called non-existent `insert_log`/`validate_key` on DB). The rest of this document outlines findings and actionable improvements to align with WordPress coding standards, improve security, and optimize for scale.

---

## 1. Critical Issues (Fixed in This Audit)

### 1.1 REST API / DB Method Mismatch — **FIXED**

- **Issue:** `class-rest-api.php` called `$db->insert_log($params)` and `$db->validate_key($key_value, $params['site_url'])`, but `class-db.php` only defines `log_syndication($data)` and has no `validate_key()`.
- **Impact:** Fatal error on any real syndication log request (only connection test could succeed).
- **Fix applied:** REST handler now uses `log_syndication()` with sanitized/normalized params; key validation is left to the existing `permission_callback` (`check_auth`). Request body is sanitized (`absint`, `esc_url_raw`, `sanitize_text_field`) and validated once before calling the DB.

### 1.2 Missing Agent Admin CSS — **FIXED**

- **Issue:** Agent enqueued `assets/admin.css` but the file did not exist (404).
- **Fix applied:** Added `synditracker-agent/assets/admin.css` with minimal styles for the agent admin UI (header, cards, connection status).

### 1.3 Agent GUID Extraction Fails for Aggregator-Imported Posts — **FIXED (v1.0.6)**

- **Issue:** The Agent used `get_the_guid($post_id)` to extract the original source post ID, looking for `?p=` format. However, when aggregators (Feedzy, WPeMatico, etc.) import posts, they create **new WordPress posts with new GUIDs** (the partner site's permalinks), not the source GUIDs.
- **Root Cause Analysis:**
  1. Source site (pinionnewswire.com) uses `pinion-publication-manager` plugin with `ensure_stable_guid()` to output `?p=58334` format GUIDs in RSS feeds ✅
  2. Feedzy imports posts and creates new GUIDs like `https://partnersite.com/the-post-title-slug-123/` ❌
  3. Agent's `extract_post_id_from_guid()` regex `/[?&]p=(\d+)/` fails on pretty permalinks
  4. Result: No syndication reports sent → Hub has no visibility → duplicates not detected
- **Discovery:** Investigated `businessnewsrelease.com` showing 20+ duplicate copies of the same article with no Hub visibility.
- **Fix applied:** New `extract_original_post_id()` method that checks multiple sources in priority order:
  1. Feedzy's `feedzy_item_url` post meta (stores original source URL)
  2. WPeMatico's `wpe_sourcepermalink` post meta
  3. RSS Aggregator's `wprss_item_permalink` post meta
  4. Fall back to WordPress GUID
- **Impact:** Agent v1.0.6 now correctly extracts source post IDs from aggregator-imported content, enabling proper duplicate detection.

---

## 2. Security

### 2.1 Good Practices Already in Place

- ABSPATH check in all PHP files.
- Nonces on AJAX and admin forms (`st_admin_nonce`, `st_agent_nonce`, `st_clear_logs_nonce`, `st_keys_nonce`, `st_alerts_nonce`, `st_key_action`).
- Capability checks (`current_user_can('manage_options')`) for admin actions.
- REST API protected by `permission_callback` and key validation.
- Log directory hardened with `index.php` and `.htaccess` (deny from all).
- Escaping in templates: `esc_html`, `esc_attr`, `esc_url`, `esc_textarea` used in most places.

### 2.2 Recommendations

| Area | Recommendation |
|------|----------------|
| **Key storage** | Keys are stored in DB; ensure DB user has minimal privileges and consider encrypting key values at rest if handling higher sensitivity (e.g. `openssl` or WordPress options encryption). |
| **Log content** | Avoid logging raw API keys or full request bodies. Already avoided in current logger usage; keep this policy. |
| **REST rate limiting** | Add rate limiting per key or per IP (e.g. transients with counters) to prevent abuse and DoS. |
| **Input validation** | REST handler now sanitizes `post_id`, `site_url`, `site_name`, `aggregator`. Consider a whitelist for `aggregator` (e.g. `Feedzy`, `WPeMatico`, `Unknown`, `Test`) to avoid stored XSS if values are ever output unsanitized. |
| **Discord webhook URL** | Stored in options; ensure only admins can set it (already so). Consider validating URL format and that it is HTTPS. |
| **CSRF on GET actions** | Key revoke/delete use `check_admin_referer('st_key_action')`; ensure links are generated with `wp_nonce_url()` (already done). |

---

## 3. WordPress Coding Standards

### 3.1 Naming & Structure

- **Classes:** WordPress prefers `Snake_Case` for class names (e.g. `Synditracker_Core`). You mix `Synditracker_Core` (root) with `Synditracker\Core\Admin` (namespaced). Both are valid; for consistency consider either all namespaced or all prefixed.
- **Functions:** Use `snake_case` for PHP functions; you already do.
- **Hooks:** Use a clear prefix; `st_`, `synditracker_` are good. Prefer a single prefix per plugin (e.g. `synditracker_` for Hub, `st_agent_` for Agent) for clarity.

### 3.2 Documentation

- Add `@since` and `@param`/`@return` to all public methods and hooks (PHPDoc). This helps IDE support and automated docs.
- Document hook names and callback priorities in the main plugin file or a dedicated `hooks.php` list.

### 3.3 Text Domain & i18n

- **Hub:** `Text Domain: synditracker`, `Domain Path: /languages` — good.
- **Agent:** `Text Domain: synditracker-agent`; no `Domain Path`. Add `Domain Path: /languages` in the plugin header.
- **Strings:** Many admin strings are hardcoded in English. Wrap all user-facing strings in `esc_html__()`, `__()`, `esc_html_x()` etc., and load the text domain on `plugins_loaded`:

  ```php
  add_action( 'plugins_loaded', function() {
      load_plugin_textdomain( 'synditracker', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
  } );
  ```

- Then create `.pot` (e.g. with WP-CLI `wp i18n make-pot`) for translation.

### 3.4 Direct DB Queries

- Prefer `$wpdb->prepare()` for any query with variables. You already do in most places.
- **Admin logs table:** `class-admin.php` line ~195 uses:

  ```php
  $logs = $wpdb->get_results("SELECT * FROM $table_name ORDER BY timestamp DESC LIMIT 50");
  ```

  Table name is from `$wpdb->prefix`, so it’s safe; no user input. For consistency and future-proofing, you could use `$wpdb->prepare( "SELECT * FROM `{$table_name}` ORDER BY timestamp DESC LIMIT %d", 50 )` (though for a constant 50 it’s optional).

### 3.5 Yoda Conditions

- WordPress standards prefer Yoda conditions for comparison (e.g. `if ( true === $x )`). Optionally run PHP_CodeSniffer with WordPress rules and fix reported comparisons.

---

## 4. Architecture & Modularity

### 4.1 Current Structure

- **Hub:** Single entry file, constants, bootstrap class; includes load REST, Admin, DB, Keys, Alerts; Logger and GitHub Updater loaded where needed. Clear separation of REST, Admin, DB, Keys, Alerts.
- **Agent:** Single entry file; settings, AJAX, and reporting logic in one class; Logger and GitHub Updater in includes.

### 4.2 Recommendations

| Topic | Recommendation |
|------|----------------|
| **Autoloading** | Replace `require_once` with PSR-4 or WordPress-style autoloading (e.g. one `includes/autoload.php` that maps class names to files). Reduces manual wiring and avoids loading unused classes. |
| **Dependency injection** | Consider passing DB, Logger, Keys into REST/Admin constructors (or a small container) instead of `::get_instance()` everywhere. Improves testability and makes dependencies explicit. |
| **Hub / Agent shared code** | Logger and GitHub Updater are duplicated. Options: (1) Composer package or shared “common” plugin, or (2) single repo with two plugin roots that share an `includes/common` via symlink or build step. Reduces drift and fixes in two places. |
| **Hooks for extensibility** | Add actions/filters for key events: e.g. `do_action( 'synditracker_after_log_stored', $data, $log_id );`, `apply_filters( 'synditracker_aggregators', $list );`. This helps enterprise integrations and extensions. |
| **Config / constants** | Centralize config (table names, option keys, REST namespace, default threshold) in one class or config file so changes are in one place. |

---

## 5. Performance & Scalability

### 5.1 Database

- **Indexes:** Logs table has `KEY post_id`, `KEY site_url`, `KEY timestamp`; keys table has `UNIQUE key_value`. Good for current usage.
- **Large datasets:** For very high volume, consider:
  - Archiving or partitioning logs by date.
  - Pagination for dashboard (e.g. `LIMIT 50 OFFSET` or cursor-based) and/or a “Load more” or date filter.
- **Metrics:** `get_metrics()` runs three separate `COUNT` queries. For scale, you could use one query with conditional aggregation or a cached transient (e.g. 5–15 min) and invalidate on new log insert.

### 5.2 REST / Agent

- Agent uses `blocking` HTTP for reporting; that’s correct for reliability. Optionally add a small queue (e.g. Action Scheduler or a custom “pending reports” table) so that if the Hub is slow, the Agent doesn’t block the user request. For most sites, current approach is acceptable.
- **Cron:** Heartbeat uses WP-Cron; on high-traffic sites consider disabling WP-Cron and triggering via system cron with `DISABLE_WP_CRON` and `wp-cron.php` to avoid duplicate runs.

### 5.3 Front-end

- Admin scripts are enqueued only on plugin pages (hook check); good.
- Consider minifying and versioning `admin.js` / `admin.css` (e.g. version = `SYNDITRACKER_VERSION` or filemtime for cache busting); you already pass version to enqueue.

---

## 6. Error Handling & Logging

### 6.1 Logger

- File-based logger with level (INFO, ERROR, DEBUG) is appropriate. Consider:
  - Making log level configurable (e.g. only log WARNING and above in production).
  - Optional log rotation or max file size to avoid filling disk.
  - Not creating the log file until the first log line (you already avoid logging keys; keep that).

### 6.2 REST Errors

- REST responses return appropriate HTTP status codes (400, 401, 403, 500) and JSON messages. Good. Ensure all `WP_Error` and exception paths are caught and returned as REST error responses so the Agent can handle them consistently.

### 6.3 Silent Failures

- `Keys::generate_key()` uses `$wpdb->insert()` but does not check the return value; on failure the caller may assume success. Return `false` on insert failure and handle it in the admin (e.g. show “Failed to generate key” and log).

---

## 7. Testing & CI

- **Unit tests:** No PHPUnit tests found. Add tests for:
  - Duplicate detection logic (`check_is_duplicate`).
  - Key validation and revoke/delete.
  - REST request validation and `log_syndication` flow (with a test DB or mocked DB).
- **Integration:** Optional E2E (e.g. Playwright) for “Agent saves settings → Hub receives log” once the test environment is stable.
- **PHPCS:** Add `phpcs.xml` with WordPress rules and run in CI to enforce coding standards.
- **Version alignment:** Hub 1.0.5 vs Agent 1.0.4; keep versions in sync when features touch both (e.g. API contract).

---

## 8. Enterprise / SaaS-Oriented Improvements

| Area | Suggestion |
|------|------------|
| **Multi-site** | If you need WP Multisite, decide whether the Hub is per-site or network-level (e.g. network admin for keys, per-site or network-wide logs). Document and test. |
| **API versioning** | REST namespace is `synditracker/v1`. Keep it; when making breaking changes, add `v2` and deprecate `v1` with clear notices. |
| **Audit trail** | Consider logging “who generated/revoked which key” and “who changed alert settings” (user_id, timestamp) for compliance. |
| **Health checks** | Expose a lightweight REST or admin endpoint for “readiness” (DB writable, cron scheduled, optional Discord reachable) for monitoring. |
| **Documentation** | Add a `docs/` or inline `README` for: required PHP/WP versions, install steps, optional constants (e.g. disable Discord), and a short API spec for the log endpoint (params, headers, responses). |
| **Uninstall** | Add `uninstall.php` to remove options and drop custom tables when the plugin is deleted (not just deactivated), so uninstall is clean. |

---

## 9. Checklist Summary

- [x] Critical REST/DB bug fixed (use `log_syndication`, remove `validate_key`, sanitize input).
- [x] Missing Agent CSS added.
- [ ] Add `Domain Path` and `load_plugin_textdomain` for both plugins; wrap strings for i18n.
- [ ] Add PHPDoc and `@since` to public methods.
- [ ] Consider autoloading and shared code for Logger / GitHub Updater.
- [ ] Add REST rate limiting and optional aggregator whitelist.
- [ ] Add `uninstall.php` for both plugins.
- [ ] Add PHPUnit tests for core logic and PHPCS to CI.
- [ ] Document API and config for enterprise users.

---

This audit and the applied fixes improve correctness, security posture, and maintainability. Implementing the remaining recommendations will align the plugin with WordPress and enterprise best practices and make it easier to extend and scale.
