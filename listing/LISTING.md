# Sheafgate Lite: directory listings

Status 2026-11-05: acceptance check SHIP (option B, 203 Pest tests at memory_limit 128M, PHPStan max, buyer check from the release zip passed). Repository https://github.com/pnscripts/filament-sheafgate-lite made public with tag v1.0.0 and a GitHub release; Packagist and the Filament plugin directory follow (requirements re-read 2026-11-05: Packagist needs a public HTTPS repository, composer.json at the root, versions from tags, `composer validate`; the Filament author portal signs in with GitHub OAuth, scopes user:email and read:org).

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
| Image (16:9, 2560x1440 JPG, 272 KB) | listing/filament-directory.jpg (06.11 after the reviewer's note: close-up of Lite's import modal, no sidebar or top bar, with the name and tagline; the full-panel image of 03.11 is kept as listing/filament-directory.old.jpg) |

Each ticked box is a public claim: dark mode and translations are verified; do not tick anything else.

## Directory image (reviewer note 06.11)

Filament's reviewer asked for an image that highlights the plugin rather than a full panel: zoom in or crop out the sidebar and top bar, and add the plugin name and a short tagline. The new image is built from the real 2560x1440 captures in `build/shots-hires/` (modal.png: the XLSX sheet, header row 3 and the mapping with example values; csv.png: the Windows-1252 detection line), cropped to the modal only, on the PN Scripts dark-blue brand background with Onest type. Source: `listing/source/filament-directory.html` (render headless, muted Chrome at 2560x1440 with the crops `modal.png` = modal.png (820,26)-(1740,1440) and `csv.png` = csv.png (840,180)-(1720,340) and the Onest fonts and white logo from marketing/social-kit/source next to it). Tagline: "XLSX, ODS and messy CSV straight into Filament's own importer". Lite has no review/preview table (that is Pro), so none is shown. The directory's image size rules are behind the author sign-in and were not readable on 06.11; the image follows 16:9, 2560x1440, JPG under 1 MB.

## Positioning

Other packages also read XLSX into Filament's importer (nearest: amjadiqbal/filament-xlsx-import, Filament 4 and 5). Never describe Sheafgate Lite as the first or only one; lead with what it adds: CSV encoding and delimiter detection, header row below title rows, merged and blank headers, an ODS reader that keeps merged cells, example values in the mapping, safe limits. Filament 5 only.

## Not verified

- MySQL, PostgreSQL and SQL Server (the suite runs on SQLite; the package itself does not query the database).
- Microsoft Excel itself was not opened; XLSX files come from LibreOffice 24.2, openpyxl and OpenSpout, ODS from LibreOffice.
- Filament releases between 5.8 and 5.11 other than those listed in the README.
