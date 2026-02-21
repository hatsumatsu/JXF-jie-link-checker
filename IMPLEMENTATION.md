# JIE Link Checker — Implementation Notes

## Configurable constants

All constants can be overridden in `wp-config.php` using `define()`.

| Constant                         | Default | Purpose                    |
| -------------------------------- | ------- | -------------------------- |
| `JIE_LINK_CHECKER_CRON_INTERVAL` | `600`   | Cron interval in seconds   |
| `JIE_LINK_CHECKER_BATCH_SIZE`    | `10`    | Posts checked per cron run |

## Cron setup

On **activation**, a recurring WP-Cron event is scheduled using the custom
`jie_link_checker_schedule` interval (derived from `JIE_LINK_CHECKER_CRON_INTERVAL`).
On **deactivation**, the event is unscheduled.

The cron hook `jie_link_checker_run` calls `JIE_Link_Checker::run()`.

## Post selection — `get_posts_to_check()`

Each cron run selects up to `JIE_LINK_CHECKER_BATCH_SIZE` published `jie` posts in two passes:

1. **Query A** — posts where `jie-link-checker-checked` meta does _not_ exist,
   ordered by `post_date ASC` (oldest unchecked first).
2. **Query B** — if Query A returned fewer than `BATCH_SIZE` results, the
   remainder is filled with posts that _do_ have `jie-link-checker-checked`,
   ordered by that value numerically ASC (checked longest ago first).

Both queries use `fields => ids`, `no_found_rows => true`, and disabled cache
warming — appropriate for a background cron context.

## Link checking — `check_post_link(int $post_id)`

For each post:

1. **Retrieve URL** via `get_post_meta($post_id, 'teaserUrl', true)`.
   `teaserUrl` is an ACF URL field stored as standard post meta — no ACF
   dependency is required in the plugin.

2. **Validate** with `wp_http_validate_url()`. Rejects empty values, relative
   paths, and private/localhost IP ranges. If invalid, the checked timestamp is
   still written so the post rotates out of Query A.

3. **HTTP check** — `wp_remote_head()` first (no body download). If the server
   returns `405 Method Not Allowed` or the connection fails at the network layer,
   falls back to `wp_remote_get()` with `stream => true` (response body written
   to a temp file rather than loaded into PHP memory).

4. **Evaluate result:**
   - `WP_Error` (network failure, DNS, SSL, timeout) — `jie-link-checker-broken`
     is left untouched; only the checked timestamp is updated.
   - HTTP `404` — `jie-link-checker-broken` is set to `'1'`.
   - Any other HTTP response — `jie-link-checker-broken` is deleted
     (auto-heals previously flagged posts).
   - Checked timestamp is always updated.

## Meta keys

| Key                        | Value                   | Written when          |
| -------------------------- | ----------------------- | --------------------- |
| `teaserUrl`                | URL string              | ACF field — read-only |
| `jie-link-checker-checked` | Unix timestamp (string) | Every check pass      |
| `jie-link-checker-broken`  | `'1'`                   | HTTP 404 confirmed    |
| `jie-link-checker-broken`  | _(deleted)_             | Non-404 HTTP response |

## Manual testing (WP-CLI)

```bash
# Trigger a cron run immediately
wp cron event run jie_link_checker_run

# Inspect results on a specific post
wp post meta get <POST_ID> jie-link-checker-checked
wp post meta get <POST_ID> jie-link-checker-broken
```
