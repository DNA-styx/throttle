# Security Review 2026

Date: 2026-05-08

Scope: full current working tree for the 2026 release branch. The app is modeled as a public crash-reporting service that accepts SourceMod Accelerator crash dumps, symbol files, and binary uploads, then exposes reports to anonymous users, owners, and admins.

## Threat Model

Primary assets:

- Crash dumps, metadata, command lines, console snippets, processing logs, SourceMod plugin/extension snapshots.
- User profiles, Steam external account identifiers, upload tokens, token usage/audit rows.
- Uploaded symbols and binaries.
- Server filesystem paths under `dumps/`, `symbols/`, `cache/`, and `var/`.
- Breakpad command execution through bundled tools.

Primary boundaries:

- Anonymous users may submit crashes to `/submit` and view public crash summaries/stacks.
- Profile token holders may submit `/submit`, `/symbols/submit`, and `/binary/submit` data attributed to their profile.
- Owners/admins may view private crash metadata, symbol coverage, SourceMod snapshots, processing logs, raw minidump views, and downloads.
- Admins may access `/health` and reprocess crashes.

## Findings and Fixes

### Low: Anonymous Client IP Exposed to Frontend Sentry Config

Affected file: `templates/base.html.twig`

Anonymous requests embedded `app.request.clientIp` in the frontend `APP_CONFIG.sentry_user` object. This was unnecessary client-side PII.

Fix: anonymous `sentry_user` is now an empty object; logged-in users still expose only their user identifier.

### Low: SourceMod Snapshot Was Rendered Outside Owner/Admin Guard

Affected file: `views/details.html.twig`

SourceMod plugin/extension snapshots can reveal server internals. The full crash metadata block was owner/admin-only, but the snapshot block was not explicitly guarded.

Fix: SourceMod snapshots now render only when `can_manage` is true.

### Low: Debug Dumps in Crash Processor Error Path

Affected file: `src/Throttle/Command/CrashProcessCommand.php`

The processor could print `$symbolCache` via `var_dump()` if a cached symbol file could not be opened. This could leak filesystem/module processing details into command output or logs.

Fix: replaced the dumps with a bounded exception message containing only the cache key.

### Low: Profile Config Generator Forced HTTP

Affected file: `src/Controller/ProfileController.php`

The generated Accelerator URLs were based on `http://` plus the request host. This could produce insecure or wrong settings behind HTTPS.

Fix: the generator now uses the current request scheme and host through `getSchemeAndHttpHost()`.

### Informational: `/submit` Accepts Untokened Crash Uploads

Affected route: `/submit`

This is intentional for Accelerator compatibility. Untokened crashes are accepted but do not create profile token usage rows. Private metadata is gated to owners/admins.

### Informational: Admin-Controlled Rich Notices

Affected files: `views/details.html.twig`, `views/index.html.twig`

Crash notices and the maintenance message are rendered as HTML. This is acceptable only while these values remain administrator/config controlled. Do not expose public write access to notice text.

## Validated Controls

- `/symbols/submit` and `/binary/submit` require a profile token, admin session, or global `SYMBOL_UPLOAD_TOKEN`.
- Symbol names and binary module names reject slash/backslash path traversal.
- Binary identifiers are restricted to alphanumeric strings.
- Crash dump file paths are generated from server-side crash IDs.
- Raw minidump, console, processing log, symbol coverage, and download routes require owner/admin access.
- Delete and reprocess actions use POST plus CSRF checks.
- `/health` requires `ROLE_ADMIN`.
- Token values are not stored in audit logs; only suffixes are recorded.
- Generated `build/`, runtime `cache/`, `dumps/`, `symbols/`, `var/`, `vendor/`, `node_modules/`, and local env files are ignored for release commits.

## Residual Risks

- Uploaded binaries are processed by external tools (`dump_syms`, `nm`). Keep this service isolated, keep upload size limits in Nginx/PHP-FPM, and run PHP-FPM as an unprivileged user.
- Public crash stack traces may still reveal module names and function names by design. Private metadata stays owner/admin-only.
- Dependency vulnerability posture must be maintained separately with regular Composer/npm updates.
