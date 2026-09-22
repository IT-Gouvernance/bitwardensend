# Changelog

All notable changes to this plugin are documented here. Format loosely follows
[Keep a Changelog](https://keepachangelog.com/) (see
[docs/README_TECHNICAL.md](docs/README_TECHNICAL.md) for why the version string matters
beyond being a label).

## [2.0.0-rc1] - 2026-09-22

### Added

- GLPI 12 compatibility (`2.0.0`, GLPI 11 no longer supported). Also fixes
  static analysis/Rector errors GLPI 12's CI catches that GLPI 11's did not:
  `$rightname` now typed `string` on `Config`/`Profile`/`Send`, every
  `Session::checkRight()`/`haveRight()` call uses a `$rightname` reference
  instead of a hardcoded right-name string, `Send`/`Config`'s
  `$undisclosedFields` and `Send::$dohistory` now carry the same native
  `array`/`bool` types `CommonDBTM` declares them with in GLPI 12,
  `EncString`'s `TYPE` class constant is now typed `int` (PHP 8.3), and
  `Send::$item->getType()` calls are now `$item::class` (GLPI 12's own
  Rector rule for this). One more hardcoded `'followup'` right-name string
  (`Send::showForItem()`, added by a later merge from the 1.0.x line) fixed
  the same way, for the same reason.
- New automatic action, "Bitwarden Send — test connection": periodically
  re-runs the same connection check as the configuration page's own "Test
  connection" button. On an unhealthy status it throws, so a run counts as
  an error the same way any other automatic action failure does — GLPI's
  own existing "Monitoring of automatic actions" notification (Setup >
  Notifications) picks it up after enough failures, with no plugin-specific
  notification machinery added for this. Defaults to every 5 minutes
  (`cleanup` stays daily) — that notification only fires after 5 errored
  runs in the last 10, so the frequency is what actually bounds how long a
  real outage goes unnoticed before the first alert; both the frequency
  and that 5-in-10 threshold are GLPI's own automatic-action settings, not
  plugin-specific ones, and remain admin-adjustable. A hint on the
  configuration page links to it, next to the
  existing one for `cleanup`, and to the actual GLPI notification that
  reports the failure (looked up by itemtype/event so it still finds it
  even if an admin has renamed it, or falls back to naming Setup >
  Notifications generically if it can't be found at all). Registered as
  `testconnection`, not `test_connection`: `CronTask::launch()` builds the
  callback method name as `itemtype::('cron' . name)` — case-insensitive
  like any PHP method call, but not underscore-insensitive, so the
  original underscored name would have made GLPI look for a method that
  does not exist and this task would never actually have run.

### Changed

- `rector.php`'s `GlpiSetList` ruleset now comes from `glpi-project/rector-glpi`
  (added to `composer.json`'s `require-dev`), GLPI's own ruleset published
  as a standalone Composer package with its own pinned version, instead of
  `require`-ing GLPI core's own `PluginsRector.php` from whatever GLPI
  checkout happens to be sitting next to this plugin. Same builder chain
  (paths, root files, cache, parallel execution, prepared sets, PHP version
  sets, the `SafeDeclareStrictTypesRector` skip) reproduced directly in
  `rector.php` instead of delegated to that file. Still needs to run from
  inside a GLPI checkout, exactly as before: GLPI core's own autoloader and
  `src/Plugin.php` are both still `require`d directly, for the actual GLPI
  classes (`CommonDBTM` and friends) this plugin's own classes extend, and
  for `registerPluginAutoloading()`'s `Plugin::class` reflection.
- `phpstan.neon`'s two rule-extension `includes` (`glpi-project/phpstan-glpi`,
  `phpstan/phpstan-deprecation-rules`) now come from this plugin's own
  `vendor/` (added to `composer.json`'s `require-dev`, alongside
  `phpstan/phpstan` itself so `vendor/bin/phpstan` exists) instead of GLPI
  core's `../../vendor/`. GLPI's own CI runs PHPStan from a prebuilt Docker
  image with GLPI core's `vendor/` baked in at build time, not reinstalled
  per run - when that image's `phpstan/phpstan-deprecation-rules` build
  drifts out of sync with the `phpstan/phpstan` version baked in alongside
  it, every plugin using it hits the same "Class ... DeprecatedScopeHelper
  not found" container error, with no fix available from a plugin's own
  repository. The CI script itself already prefers a plugin's own
  `vendor/bin/phpstan` over GLPI core's when present, so pinning these
  three packages here sidesteps the image's staleness entirely. GLPI
  core's own autoloader and `src/` stay the `bootstrapFiles`/
  `scanDirectories` source, unchanged - only the static analysis tool
  itself and its rule packages moved, not the GLPI classes being analyzed
  against.

### Fixed

- `Send::cronTestConnection()` could block the page of whichever user
  happened to trigger it (it runs in `CronTask::MODE_INTERNAL`, piggybacked
  on a random page request — deliberately, so it works with no system cron
  configured) for up to the driver's full configured timeout (up to 120s)
  reaching an unresponsive Bitwarden — exactly when that slowness is what
  the check exists to catch. Caps the timeout at 5 seconds for this call
  specifically, leaving the configured value untouched for a technician
  deliberately waiting on a real Send. (An earlier version of this fix
  instead had the native driver's `testConnection()` skip the PBKDF2
  master-key derivation, reasoning its result went unused — true for the
  return value, but that derivation failing is itself the signal that a
  wrong master password, or an Argon2id account this driver cannot handle
  at all, is configured. Skipping it made both the health check and the
  configuration page's own manual "Test connection" button report healthy
  in exactly those two broken states. Reverted; the timeout cap addresses
  the actual page-blocking concern without that regression.)
- `ajax/followup_template.php` set `Content-Type: application/json` before
  validating the request, so an error response (thrown as one of GLPI's
  own HTTP exceptions) was rendered by GLPI's kernel under an already-sent
  JSON header — harmless (the client already tolerates a non-JSON error
  body), but mislabelled. Moved after validation. Also added a
  `json_encode()` failure check: invalid UTF-8 in a rendered template
  would have silently produced an empty `200` body instead of a clear
  error.
- The "Bitwarden Sends" tab exposed a Send's stored access link (when "Keep
  the link in the GLPI database" is on) to anyone with the plugin's own
  `READ` right and view access to the ticket, regardless of whether that
  Send's link was posted as a **private** followup specifically to keep it
  from users without `ITILFollowup::SEEPRIVATE` (e.g. the requester, or
  support staff without that right). The tab has no record of which
  followup a Send was posted with, so `Send::showForItem()` now only
  decrypts and shows the link to viewers who either hold `SEEPRIVATE` or
  are the Send's own creator — treating every stored link as at least that
  sensitive, since it is a bearer URL granting direct access to the shared
  secret.
- The followup preview's rich-text sanitizer stripped tab/newline/CR from
  inside a URL before checking its scheme, but only leading whitespace
  (`\s`) before it — missing the rest of the C0 control range (e.g.
  `U+0001`–`U+0008`, `U+000E`–`U+001F`) a browser also trims from the
  front of a URL before parsing it. A payload with one of those leading
  `javascript:`/`data:`/`vbscript:` slipped past the check but still ran
  on click. Now strips the whole `\x00`–`\x20` range before testing. ESLint's
  `no-control-regex` rule flagged that range as suspicious (it normally
  catches accidental control characters in a regex) — annotated as
  intentional instead of narrowing it.
- `Config::isLoopbackHost()` compared the host against the bare string
  `::1`, but `parse_url()` keeps the brackets on an IPv6 host (`[::1]` for
  `http://[::1]:8087`), so a literal IPv6 loopback URL never matched and
  was rejected as "not loopback" — despite docs/README_TECHNICAL.md
  documenting `::1` as supported. Strips the brackets before comparing.
- The Send creation form's GLPI followup template selector rendered every
  visible template through Twig (`TemplateManager::renderContentForCommonITIL()`)
  on every form load, before any of them was ever picked — not just the one
  the technician selected. Since GLPI's own sandboxed Twig policy allows
  unbounded loops (`{% for %}`/`range()`), a user with `itilfollowuptemplate`
  `UPDATE` could author a template that pins a PHP-FPM worker for the
  duration every time the Send form opens in that entity, or one that
  errors on render and shows an error message on every form open. Template
  content is now rendered on demand instead, via a new
  `ajax/followup_template.php` endpoint, only for the one template actually
  selected — the same right/entity/`is_active` scoping as the list it was
  picked from, matching how GLPI core's own `ajax/itilfollowup.php` renders
  exactly one template on demand for a plain followup.
- `tests/NativeSendDriverIntegrationTest.php` tried to read a created Send
  back the way a real recipient would, to independently verify its
  encrypted content — the premise turned out to be wrong once actually run
  against a real account: that route is not anonymous on current Bitwarden.
  The real backend (`bitwarden/server`'s `SendsController`) requires a
  Bearer token carrying the Send's id as a claim, obtained through a
  separate token exchange this test never implemented (two earlier attempts
  assumed a plain unauthenticated `GET`/`POST` and both 404'd against a real
  account). Simplified the test to what it can actually prove without that
  token exchange: that creating and revoking a Send round-trips against a
  live account. `NativeSendDriver` itself never reads a Send back this way,
  so the plugin's own behavior is unaffected either way.
- The followup preview's rich-text sanitizer stripped `javascript:`/`data:`/
  `vbscript:` from `href`/`src`/etc. but only checked the scheme after
  leading whitespace, not after removing embedded tab/newline/CR characters
  the way a browser itself does before resolving a URL — a scheme like
  `jav` + tab + `ascript:` slipped past the check unmodified but still ran
  as a working `javascript:` URI once clicked. Now strips those characters
  before testing, matching the WHATWG URL Standard's own first parsing
  step.
- A GLPI followup template picked on the creation form (as an alternative to
  the plugin's own configured template) was inserted as raw, unrendered
  text. A template using GLPI's own Twig-based content placeholders (e.g.
  `{% for user in ticket.requesters.users %}{{ user.firstname }}{% endfor %}`)
  showed those tags literally instead of the data they resolve to.
  `Send::getFollowupTemplatesForItem()` now renders each template's content
  against the item through `Glpi\ContentTemplates\TemplateManager` — the same
  call GLPI's own `ajax/itilfollowup.php` makes when picking a template for a
  plain followup — falling back to the raw content if rendering fails.
- `Send` did not declare `$undisclosedFields` for `access_url`, unlike
  `Config`'s own three encrypted fields — added it, so the stored (still
  GLPIKey-encrypted) value is masked the same way wherever core relies on
  that property (REST API item output, search/list, Dropdown/Link
  rendering).
- `Config::showConfigForm()` passed the entire configuration row, including
  the three encrypted secret fields, into the config page's Twig context —
  the template only ever reads the pre-computed `has_*` booleans for those.
  Removed the three raw values from the context before rendering.

## [1.0.1] - 2026-09-21

### Fixed

- The "Bitwarden Sends" tab exposed a Send's stored access link (when "Keep
  the link in the GLPI database" is on) to anyone with the plugin's own
  `READ` right and view access to the ticket, regardless of whether that
  Send's link was posted as a **private** followup specifically to keep it
  from users without `ITILFollowup::SEEPRIVATE` (e.g. the requester, or
  support staff without that right). The tab has no record of which
  followup a Send was posted with, so `Send::showForItem()` now only
  decrypts and shows the link to viewers who either hold `SEEPRIVATE` or
  are the Send's own creator — treating every stored link as at least that
  sensitive, since it is a bearer URL granting direct access to the shared
  secret.
- The followup preview's rich-text sanitizer stripped tab/newline/CR from
  inside a URL before checking its scheme, but only leading whitespace
  (`\s`) before it — missing the rest of the C0 control range (e.g.
  `U+0001`–`U+0008`, `U+000E`–`U+001F`) a browser also trims from the
  front of a URL before parsing it. A payload with one of those leading
  `javascript:`/`data:`/`vbscript:` slipped past the check but still ran
  on click. Now strips the whole `\x00`–`\x20` range before testing. ESLint's
  `no-control-regex` rule flagged that range as suspicious (it normally
  catches accidental control characters in a regex) — annotated as
  intentional instead of narrowing it.
- `Config::isLoopbackHost()` compared the host against the bare string
  `::1`, but `parse_url()` keeps the brackets on an IPv6 host (`[::1]` for
  `http://[::1]:8087`), so a literal IPv6 loopback URL never matched and
  was rejected as "not loopback" — despite docs/README_TECHNICAL.md
  documenting `::1` as supported. Strips the brackets before comparing.
- The Send creation form's GLPI followup template selector rendered every
  visible template through Twig (`TemplateManager::renderContentForCommonITIL()`)
  on every form load, before any of them was ever picked — not just the one
  the technician selected. Since GLPI's own sandboxed Twig policy allows
  unbounded loops (`{% for %}`/`range()`), a user with `itilfollowuptemplate`
  `UPDATE` could author a template that pins a PHP-FPM worker for the
  duration every time the Send form opens in that entity, or one that
  errors on render and shows an error message on every form open. Template
  content is now rendered on demand instead, via a new
  `ajax/followup_template.php` endpoint, only for the one template actually
  selected — the same right/entity/`is_active` scoping as the list it was
  picked from, matching how GLPI core's own `ajax/itilfollowup.php` renders
  exactly one template on demand for a plain followup.
- `tests/NativeSendDriverIntegrationTest.php` tried to read a created Send
  back the way a real recipient would, to independently verify its
  encrypted content — the premise turned out to be wrong once actually run
  against a real account: that route is not anonymous on current Bitwarden.
  The real backend (`bitwarden/server`'s `SendsController`) requires a
  Bearer token carrying the Send's id as a claim, obtained through a
  separate token exchange this test never implemented (two earlier attempts
  assumed a plain unauthenticated `GET`/`POST` and both 404'd against a real
  account). Simplified the test to what it can actually prove without that
  token exchange: that creating and revoking a Send round-trips against a
  live account. `NativeSendDriver` itself never reads a Send back this way,
  so the plugin's own behavior is unaffected either way.
- The followup preview's rich-text sanitizer stripped `javascript:`/`data:`/
  `vbscript:` from `href`/`src`/etc. but only checked the scheme after
  leading whitespace, not after removing embedded tab/newline/CR characters
  the way a browser itself does before resolving a URL — a scheme like
  `jav` + tab + `ascript:` slipped past the check unmodified but still ran
  as a working `javascript:` URI once clicked. Now strips those characters
  before testing, matching the WHATWG URL Standard's own first parsing
  step.
- A GLPI followup template picked on the creation form (as an alternative to
  the plugin's own configured template) was inserted as raw, unrendered
  text. A template using GLPI's own Twig-based content placeholders (e.g.
  `{% for user in ticket.requesters.users %}{{ user.firstname }}{% endfor %}`)
  showed those tags literally instead of the data they resolve to.
  `Send::getFollowupTemplatesForItem()` now renders each template's content
  against the item through `Glpi\ContentTemplates\TemplateManager` — the same
  call GLPI's own `ajax/itilfollowup.php` makes when picking a template for a
  plain followup — falling back to the raw content if rendering fails.
- `Send` did not declare `$undisclosedFields` for `access_url`, unlike
  `Config`'s own three encrypted fields — added it, so the stored (still
  GLPIKey-encrypted) value is masked the same way wherever core relies on
  that property (REST API item output, search/list, Dropdown/Link
  rendering).
- `Config::showConfigForm()` passed the entire configuration row, including
  the three encrypted secret fields, into the config page's Twig context —
  the template only ever reads the pre-computed `has_*` booleans for those.
  Removed the three raw values from the context before rendering.

## [1.0.0] - 2026-09-08

Initial release.

### Added

- Entry in the timeline's "answer" split button (next to Followup/Task/Solution/...)
  and "Bitwarden Sends" tab on Ticket, Change and Problem.
- Text Send creation: expiration, view count, password, sender email hiding.
- Random password generator next to the Link password field, toggleable with "Show a
  random password generator on the creation form" on the configuration page.
- Random generator for the Content to share field itself, with its own length and
  character-class options (uppercase, lowercase, numbers, symbols) — same toggle as
  above.
- Rich text followup message (GLPI's own TinyMCE editor) with a live preview
  substituting `{expiration}` / `{max_access}` as the form changes.
- Followup text source selector on the creation form: the plugin's own configured
  template, or any of GLPI's own followup templates ("Setup > Templates > Followup
  templates"), scoped to the item's entity. The `{url}`, `{expiration}` and
  `{max_access}` variables work in either case. Toggle with "Allow choosing a GLPI
  followup template when creating a Send" on the configuration page.
- Revoke a link from the tab; delete the GLPI-side record once revoked or expired.
- "Expired" status computed from the link's expiration date, independent of whether it
  was explicitly revoked.
- Daily automatic action (`Send::cronCleanup`) purging old revoked/expired entries,
  with its retention configured as a native GLPI CronTask parameter (Setup > Automatic
  actions), not on the plugin's own configuration page.
- Dedicated right `plugin_bitwardensend_send` (Administration > Profiles), with an
  in-tab explanation of how the four rights relate to each other.
- CLI Send driver, talking to the official `bw` client's local Vault Management API
  (`bw serve`).
- Native (pure PHP) Send driver, talking to the Bitwarden API directly with no `bw`
  binary and no shell access required — for hosts where the CLI driver's server
  prerequisites cannot be met, e.g. GLPI Cloud. Toggle with "Send driver" on the
  configuration page; only supports service accounts using the PBKDF2 KDF (Argon2id
  cannot be reproduced with PHP's available primitives — see docs/README_TECHNICAL.md).
- Configuration secrets (master password, native driver's API client secret and
  master password) encrypted with the GLPI key.
- French translations (`fr`, `fr_FR`, `fr_BE`, `fr_CA`), generated by
  `tools/build-locales.py` without requiring `xgettext`/`msgfmt`.
- Spanish translations (`es`, `es_ES`). `tools/build-locales.py` now builds
  any number of languages from a `LANGUAGES` list instead of being wired for
  French alone — adding another one is a matter of adding its own
  translation dict and appending an entry, nothing else in the script
  changes.
- GitHub Actions workflows: automatic translation catalog rebuilds on every relevant
  push, and automatic GitHub Releases (with a `glpi-bitwardensend-<version>.tar.bz2`
  archive) on every version bump.
- Community/contribution files: `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`,
  `SECURITY.md`, a pull request template, and bug report/feature request issue
  forms.
- More standalone PHPUnit coverage: `SendCrypto::zero()` and its non-positive
  KDF iteration count rejection, plus `CliSendDriver`/`NativeSendDriver`'s
  `isAvailable()` (both pure config checks, no GLPI bootstrap needed as long
  as the config is passed explicitly to the constructor).
- "Server" preset selector next to the native driver's Identity/API/Web vault
  URL fields: picking "US — bitwarden.com" or "EU — bitwarden.eu" fills in
  the three official URLs for that region and hides them (nothing to edit);
  "Self-hosted / custom" shows them for editing and leaves them as they are.
  A convenience only — nothing new is stored, it just writes into the three
  existing fields, and pre-selects itself on reload by matching their
  current values against the two known presets.

### Fixed

- "Private followup (hidden from the requester)" stayed visible on the creation form
  even when "Post the link as a followup" was unchecked. It now lives inside the same
  hideable block as the rest of the followup section, and that block itself starts
  hidden when followups are off by default.
- `{url}` expands to a whole `<a href="...">...</a>` tag, which broke the markup (and
  its live preview) when placed inside a custom `href="..."`, e.g. a GLPI followup
  template with its own link wording. Added `{url_raw}` — the bare, attribute-safe
  URL — for exactly that case; `{url}` is unchanged for everywhere else.
- A bare `{url_raw}` typed directly into a `href="..."` on a rich text field (a GLPI
  followup template, or the create form's own followup text) still got corrupted: GLPI's
  editor rewrites any href it does not recognize as an already-absolute URL, prefixing
  it with the GLPI base URL, before this plugin ever sees the saved content. Use
  `https://bitwardensend.invalid/{url_raw}` there instead — already absolute, so the
  editor leaves it alone; this plugin still resolves it to the real link.
- The timeline button used the pre-GLPI-10.0 `timeline_actions` hook, which renders
  into a separate "legacy" toolbar rather than alongside Followup/Task/Solution/... —
  visually disconnected from those, and from any other plugin using the current hook.
  Switched to `timeline_answer_actions`: the button is now generated by GLPI itself
  (consistent styling, no plugin CSS needed) and its form opens inline in the timeline
  instead of a popup. The "Bitwarden Sends" tab keeps its own popup-based creation
  button as a second entry point to the same form.
- The Revoke icon in the "Bitwarden Sends" tab showed up regardless of rights (the
  server already blocked the action itself); it now only appears when the profile has
  the UPDATE right, matching how the Delete icon already behaved for PURGE.
- The plugin's rights tab on Administration > Profiles showed up for simplified
  ("helpdesk") interface profiles too, even though this plugin's tab and timeline
  action never render under that interface — any rights granted there could never
  have a visible effect. That tab is now limited to "central" interface profiles.
- Several code paths read values from request input, stored configuration or API
  responses without checking their type first, which static analysis (phpstan at
  `level: max`) now flags as unsafe even where the runtime behavior was already
  correct. Added explicit type checks throughout, plus a couple of small real bugs
  this turned up: a failed `json_encode()` of a Bitwarden Send request body was
  passed to curl as `false` instead of being treated as an error, and
  `SendCrypto::deriveMasterKey()` did not reject a non-positive KDF iteration count
  before handing it to `hash_pbkdf2()`.
- Two error messages added along with the type-safety fixes above ("Could not
  compute the expiration date.", "Unable to encode the request body") had no
  French translation, so `tools/build-locales.py` failed. Added them.
- `Send::createFromInput()` posted the followup through `ITILFollowup::add()`
  directly, which does not enforce GLPI's own actor-aware followup rights
  (`ADDMY`, `ADD_AS_TECHNICIAN`, closed-status handling, ...) the way
  `front/itilfollowup.form.php` does. The plugin's own CREATE right on the
  Send does not imply the right to add a followup on the item — now checked
  explicitly with `canAddFollowups()` before creating the Send at all, and
  the "private" flag is only honored when the requesting user actually has
  the right to see private followups.
- The error message added along with the followup authorization fix above
  ("You are not allowed to add a followup on this item.") had no French
  translation, so `tools/build-locales.py` failed. Added it.
- The CLI driver's "Send link base URL" (the fallback used to build the
  access link when the local API response omits it) was saved with no
  scheme check, unlike every other configured URL. Since this one becomes
  part of the link handed to end users rather than a URL the server itself
  calls, a non-`http(s)` value here was a phishing/credential-harvesting
  risk rather than an SSRF one. Now validated the same way, only when a
  value is actually set (it stays optional).
- Deleting a Send from the tab only ever required the plugin's PURGE right
  and the parent item's visibility — nothing checked whether the Send was
  actually revoked or expired first, unlike the "Delete" button itself,
  which the list only ever shows for one of those two states. Deleting a
  still-active Send dropped GLPI's only record of it (`send_uuid`) while
  the Bitwarden Send kept working for anyone who already had the link, for
  up to its full expiration, with no way left to revoke it or even notice
  it existed. `Send::pre_deleteItem()` now revokes an active Send on the
  Bitwarden side before its local row can be purged, and blocks the purge
  entirely if that revoke fails.
- The followup live preview assigned the rich text editor's content to
  `innerHTML` as-is. The plain `<textarea>` fallback was already escaped
  first, but the TinyMCE path was not — and that content can come from a
  GLPI followup template authored by anyone holding `itilfollowuptemplate`
  UPDATE, not only an admin. Both paths now go through a small sanitizer
  (parses into a detached document, strips `<script>`/`<iframe>`/etc.,
  `on*` attributes and `javascript:` hrefs/srcs) before reaching the page.
- `Profile::displayTabContentForItem()` was the one tab-content method in the
  plugin that did not re-check its own right — `Config`'s and `Send`'s
  already do, since `getTabNameForItem()` only gates the tab's label, not
  its content, and GLPI's generic tab dispatcher can reach the content
  method directly. Now checks `profile` READ itself too, matching the other
  two.
- The CLI driver accepted the configured endpoint's `accessUrl` response
  field with no scheme check, unlike the `send_base_url` fallback used when
  that field is absent (already scheme-checked before being saved). That
  value ends up in a followup's `href`, so a compromised or malicious
  endpoint answering with e.g. a `javascript:` URL would have reached it
  as-is. Now validated the same way regardless of which of the two sources
  it came from.
- The Send creation form's context (`Send::buildFormContext()`) carried the
  entire configuration row, including the encrypted credential fields
  (`master_password`, `native_client_secret`, `native_master_password`) —
  only seven non-secret settings are actually used by the form. It now
  passes just those.
- The configured URLs (CLI driver's Local API URL, the native driver's three
  URLs) accepted plain `http://` for any host, not just the default loopback
  one. Several of the requests they receive carry a secret in the body (the
  vault master password, the native driver's client secret), so pointing one
  of these at a remote host while leaving it on `http://` sent that secret
  in the clear. `http://` is now only accepted for a loopback host
  (`127.0.0.1`, `::1`, `localhost`); anything else must be `https://`.
- The followup preview sanitizer's scheme check only matched the exact
  attribute names `href`/`src`, so an SVG `<a xlink:href="javascript:...">`
  (whose attribute name is literally `xlink:href`) sailed through untouched.
  Now matched by local name (after any `prefix:`), covers a few more
  navigating attributes (`action`, `formaction`, `poster`, `data`), and the
  blocked-scheme pattern also covers `data:`/`vbscript:`, not just
  `javascript:`. The `data:` addition can hide a pasted inline image in the
  live preview (harmless — the real, submitted followup is unaffected, only
  this preview rendering).
- `bitwardensend.xml`'s `<tags>` gained an `<en>` and a `<fr>` block but no
  `<es>` one when Spanish support was added. Added it.