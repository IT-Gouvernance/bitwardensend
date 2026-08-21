# Contributing

Thanks for taking the time to contribute! This document covers what you need to
know to open an issue or a pull request against this plugin.

By participating in this project, you agree to abide by its
[Code of Conduct](CODE_OF_CONDUCT.md).

## Before you start

- For a bug, check the [open issues](../../issues) first — someone may have
  already reported it.
- For a new feature or a behavior change, open an issue to discuss it before
  writing code. This plugin has a fairly narrow scope by design (see the
  [README](README.md) and [docs/README_TECHNICAL.md](docs/README_TECHNICAL.md));
  discussing first avoids spending time on a pull request that does not fit.
- For a security vulnerability, please **do not** open a public issue — see
  [Reporting a vulnerability](#reporting-a-vulnerability) below.

## Development setup

This is a GLPI plugin: it needs a GLPI 11 checkout to run against. Clone this
repository into that checkout's `plugins/` directory as `bitwardensend`:

```bash
cd /path/to/glpi/plugins
git clone https://github.com/IT-Gouvernance/bitwardensend.git
```

Then install/enable it from Setup > Plugins like any other plugin. See
[docs/README_TECHNICAL.md](docs/README_TECHNICAL.md) for the two Send drivers
(CLI vs. native) and what each needs to actually create a Send during manual
testing.

## Code quality checks

The configuration for every tool below comes from
[`pluginsGLPI/empty`](https://github.com/pluginsGLPI/empty), adapted to this
plugin's own paths. Run them from the plugin's root, inside a GLPI checkout
(they resolve tools from GLPI core's own `vendor/`/`node_modules/`, not this
repository's):

```bash
../../vendor/bin/phpstan            # static analysis (level: max)
../../vendor/bin/psalm              # static analysis, including taint tracking
../../vendor/bin/php-cs-fixer fix   # PHP coding standard (auto-fixes)
../../vendor/bin/rector process     # automated refactoring (auto-fixes)
../../vendor/bin/twigcs             # Twig template coding standard
../../node_modules/.bin/eslint      # JavaScript linting
```

The same checks run in CI on every pull request (`.github/workflows/`) against
the GLPI versions declared in `setup.php`. A PR won't be merged with any of
them failing, so it's worth running the ones relevant to what you touched
before opening it.

## Translations

Don't edit `locales/*.po`/`*.mo` by hand. Add new user-facing strings through
`__('...', 'bitwardensend')` (or `_n(...)` for plurals) in PHP/Twig, then
regenerate the catalogs:

```bash
python3 tools/build-locales.py
```

The script fails loudly if a new string has no French translation yet, or if
`locales/bitwardensend.pot` has an entry no longer referenced anywhere — add
the missing translation (or remove the stale one) directly in
`tools/build-locales.py`'s own `FR` dictionary, then rerun it.

## Commit and pull request conventions

- Keep commits focused; a commit message explaining *why*, not just *what*, is
  more useful than a longer diff.
- Add a [CHANGELOG.md](CHANGELOG.md) entry under `### Added`/`### Fixed` for
  anything a user or admin would notice.
- Target `main` with your pull request. CI checks whether `CHANGELOG.md` was
  updated (skipped for locale-only or CI-only changes).
- Describe *why* a change is needed, not just what changed — that's the part a
  diff alone doesn't show.

## Reporting a vulnerability

If you believe you've found a security issue, please report it privately
rather than through a public GitHub issue — either via GitHub's
["Report a vulnerability"](../../security/advisories/new) form on this
repository, or by contacting [IT Gouvernance](https://www.it-gouvernance.fr/)
directly. Please include enough detail to reproduce the issue and, if you can,
an assessment of its impact.
