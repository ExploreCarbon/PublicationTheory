# Sentinel Publication Workflow

## Running tests
Uses WP core test suite (wp-phpunit) against a dedicated MySQL database `spw_test`
(the suite drops tables; the bootstrap refuses `DB_NAME=local`).

1. Create DB: `CREATE DATABASE IF NOT EXISTS spw_test;`
2. `composer install`
3. `composer test`

Defaults: host `127.0.0.1:10096`, user/pass `root`/`root`. Override with env
`SPW_TEST_DB_HOST`, `SPW_TEST_DB_USER`, `SPW_TEST_DB_PASSWORD`, `SPW_TEST_DB_NAME`.

Notes: PHP needs the `mysqli` extension (Local's PHP ships without a php.ini; point `PHPRC` at an ini that loads it).
`wp-phpunit` is pinned to 7.1.2 (7.1.3 was published with missing files). WP core is found by walking up to `wp-settings.php`
(override with `SPW_WP_ABSPATH`).


## What it does
Adds an editorial review workflow to covered post types (every public post type shown in REST except attachments and block/template types; see `spw_covered_post_types`).

- **Roles and capabilities.** Nothing is written to stored roles. A `user_has_cap` filter denies `publish_*` for covered types to anyone without `edit_others_*` (Authors, Contributors). Authors submit for review; Editors and Administrators publish. Authors edit *staged copies* of live content instead of the live post.
- **Phase 1:** Submit for review, Review Queue (top-level menu with pending badge, `edit_others_posts`), send back with a required note, feedback history (post meta `_spw_feedback`), sidebar panel in the block editor.
- **Phase 1b:** Staged edits. An Author opening a live item gets a private draft copy; submitting it puts it in the queue as a "Staged edit" item. Editors review a field-by-field diff (title, content, excerpt, featured image, allow-listed meta, terms), then approve (3-way merge into the live post, copy removed) or send back. Copies are hidden from front end, sitemaps and REST listings. Approve copies the copy's feedback history and notes to the live post. **Discard** (review screen, live-post notice for Editors, or the interstitial for the copy's own author) permanently deletes a copy and its notes; the live item is unchanged. Another Author who opens a held item is told who holds the copy and to ask an Editor to discard it. Publishing a copy from the editor is refused ("Use Approve on the review screen").
- **Phase 2:** WordPress Notes (block comments) enabled on covered types; editing presence notice ("X is also editing") via Heartbeat and the post edit lock.
- **Dashboard widgets.** *Review Queue* (needs `edit_others_posts`: counts, oldest five, links to the queue or review screen), *My Submissions* (the viewer's own items in review, sent back with the latest note, or staged), *Recent Workflow Notifications* (`manage_options`: last 10 log entries). Standard dashboard widgets; hide or move via Screen Options.
- **Switch to draft.** Editors/Admins may move a pending item back to draft through the normal UI without a note; the plugin records an automatic feedback entry ("Returned to draft by X without a comment.") and emails the author.
- **Emails.** Events: `submitted`, `sent_back`, `published`, `staged_submitted`, `staged_approved`. `published` is sent only when a user publishes or a scheduled post goes live (not for imports/CLI with no user). Failures and sends are recorded in **Tools > Workflow Log** (last 500 entries, option `spw_workflow_log`, `manage_options`).

## Filters
| Filter | Purpose |
| --- | --- |
| `spw_covered_post_types` | Array of post type slugs the workflow covers. |
| `spw_email_recipients` | `( int[] $user_ids, string $event, int $post_id )` recipient user IDs. |
| `spw_email_message` | `( array $message, string $event, int $post_id )` subject/body override. |
| `spw_queue_item_kind` | `( string $kind, int $post_id )` "Kind" column label in the queue (default "New"; staged copies say "Staged edit"). |
| `spw_allowed_live_meta_keys` | `( string[] $keys )` meta keys (a trailing `_` marks a prefix) that requests by non-publishers may still write on **live** posts (housekeeping such as `_oembed_`). `_spw_*` is never allowed. |
| `spw_notify_enabled` | `( bool $enabled, string $event, int $post_id )` return false to veto a workflow email. |
| `spw_build_dir` | Directory holding the editor build (default `build/`). |

## Deactivation and uninstall
Every restriction is a runtime hook, so deactivating restores core behavior: Authors can publish and edit their posts again, and staged copies stay as ordinary draft/pending posts (with `_spw_staged_for` style meta). Nothing is deleted on deactivation. `uninstall.php` removes only the `spw_workflow_log` option; feedback meta and staged copies are retained deliberately. Covered by `tests/test-lifecycle.php`.

## Build
The editor UI source is in `src/`; the compiled `build/` is **committed** (no build step on deploy). After editing `src/`:

```
npm ci
npm run build        # wp-scripts build; on low memory: node --max-old-space-size=1536 node_modules/@wordpress/scripts/bin/wp-scripts.js build
git diff --exit-code build/   # must be clean once the regenerated build is committed
```

## Windows / Local notes
Local's PHP has no php.ini: put the Local PHP `bin/win32` directory on `PATH` and set `PHPRC` to a directory containing a `php.ini` with `extension_dir`, `extension=mysqli`, `extension=mbstring`, `extension=openssl`, `extension=curl`. Tests use a MySQL DB named `spw_test` only (default host `127.0.0.1:10096`, Local's MySQL port for this site); the bootstrap refuses any DB name not starting with `spw_test`.

## Known limitations
- Authors cannot moderate comments on their own live posts (`edit_comment` maps to `edit_post`, which is denied on live content).
- Notes on live posts are not readable by Authors (they have no edit access to the live post; they see notes on their staged copy).
- `wp_count_posts()` (core list-table status counts, dashboard) includes staged copies.
- Live co-editing is **not** included. That is a separate Phase 3 spike (REST polling, since WP Engine does not suit websockets).
- Static accessibility audit only; no assistive-technology or browser run has been done (see checklist).

## Deploy
- Dev-only (not needed on the server): `tests/`, `src/`, `composer.json`, `phpunit.xml.dist`, `package.json`, `package-lock.json`. Test PHP files exit when requested over the web.
- Activate the plugin in wp-admin after deploy (there is no activation hook).

## Manual QA checklist (browser, not yet done)
1. Author: create draft, Submit for review; status becomes Pending; editors get email; Workflow Log shows the send.
2. Editor: Review Queue badge count matches; Send back with empty note is blocked; with a note, author sees "Latest feedback" and gets an email.
3. Author: open a live post, interstitial appears (single h1), "Edit a copy for review" opens the draft copy; submit; queue shows "Update".
4. Editor: open the staged review screen; diff is readable without colour; Approve updates the live post and removes the copy; Send back returns the copy with the note.
5. Notes: add a Note with an @mention on a staged copy and on a live post; mentioned user is emailed; note carries to the live post on approve.
6. Presence: open the same post in two sessions; second session shows the warning notice; screen reader announces it once; it clears when the first user leaves.
7. Editor panel: tab through panel controls with keyboard only; focus ring visible; errors and success are announced; at 200% zoom/320px nothing is clipped.
8. Deactivate the plugin in Local: Author can publish; staged copies remain drafts; reactivate.
