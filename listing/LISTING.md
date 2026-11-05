# Sheafgate Lite: directory listings

Status 2026-11-03: built and tested (189 Pest tests at memory_limit 128M after security review round 8, PHPStan max), buyer check from the release archive passed (see the build notes for versions). Not submitted anywhere. Planned public repository https://github.com/pnscripts/filament-sheafgate-lite with tag v1.0.0; the coordinator creates it.

## Packagist (owner step: sign-in)

1. Sign in at https://packagist.org with the PN Scripts account.
2. Submit → paste `https://github.com/pnscripts/filament-sheafgate-lite` → Check → Submit.
3. Enable the GitHub hook (or the PNScripts GitHub integration) so new tags publish themselves.
4. Check: the page shows `pnscripts/sheafgate-lite`, author PN Scripts <office@pnscripts.com>, licence MIT, version 1.0.0, the funding link to the Pro page; `composer require pnscripts/sheafgate-lite` works in a fresh Laravel 13 app with Filament 5.

Before submitting, check that https://pnscripts.com/marketplace/filament-sheafgate answers 200 (it is the funding link and the "Pro, Coming soon" link in the README).

## Filament plugin directory (owner step: account, then the author area)

The directory is managed on filamentphp.com: sign in with GitHub at https://filamentphp.com/author (requested and approved author profile), then submit the plugin in the author area. The plugin must be on Packagist first. Listings are marked "community-built, not reviewed by Filament". Fields as shown on current plugin pages (checked 2026-11-03 on a Filament 5 plugin page: name, author, price, categories, supported versions, dark mode, translations, links, image):

| Field | Value |
|---|---|
| Author name | PN Scripts |
| Author website | https://pnscripts.com |
| Author GitHub | pnscripts |
| Plugin name | Sheafgate Lite |
| Short description | Messy spreadsheets into Filament 5's own importer: CSV in any encoding and delimiter, header rows below titles, merged headers, ODS, example values in the mapping, safe limits. Filament's mapping, validation and failed rows keep working. |
| GitHub repository | pnscripts/filament-sheafgate-lite |
| Documentation URL | https://github.com/pnscripts/filament-sheafgate-lite#readme |
| Price | Free |
| Supported Filament versions | 5.x (composer: ^5.8) |
| Categories / tags | Action, Tables |
| Dark mode | Yes (only Filament components; screenshot docs/images/dark.jpg) |
| Translations | Yes (English and Bulgarian, publishable) |
| Image (16:9, 2560x1440, light theme, plugin running) | listing/filament-directory.jpg (a Windows-1252 CSV read in Lite's modal) |

Each ticked box is a public claim: dark mode and translations are verified; do not tick anything else.

## Positioning

Other packages also read XLSX into Filament's importer (nearest: amjadiqbal/filament-xlsx-import, Filament 4 and 5). Never describe Sheafgate Lite as the first or only one; lead with what it adds: CSV encoding and delimiter detection, header row below title rows, merged and blank headers, an ODS reader that keeps merged cells, example values in the mapping, safe limits. Filament 5 only.

## Not verified

- MySQL, PostgreSQL and SQL Server (the suite runs on SQLite; the package itself does not query the database).
- Microsoft Excel itself was not opened; XLSX files come from LibreOffice 24.2, openpyxl and OpenSpout, ODS from LibreOffice.
- Filament releases between 5.8 and 5.11 other than those listed in the README.
