# Public website review, September 30, 2026

Implements `/`, `/getting-started`, `/compatibility` and `/docs` from the approved orange concept. Shared public assets are scoped to `.public-site`; account and dashboard assets, authentication, Go contracts and dependency locks are unchanged.

## Source and release dependency

Built in a fresh worktree from deployed PHP revision `bb384f90cf995e1165cc57e121ebc4347a8913b2`. Control-plane PR #35 remains open on `feat/private-use-php-final`, 16 commits ahead of main at `b11529f4dd788cd819ca961c5403214db373c3ee`. The website PR must be stacked on that release branch. No merge or deployment is authorized.

Central tracking: https://github.com/goformx/goformx/issues/205, linked to roadmap #84 and milestone 8.

## Verification

`composer check` passed on Windows with PHP 8.5.5 and Node 24:

- Strict site diagnostics: zero findings.
- Offline site verification: SiteContractTest (1 test, 3 assertions) and SiteGoldenPathTest (1 test, 4 assertions) passed.
- PHP suite: 222 tests, 1,926 assertions, two existing skips.
- UI suite: 17 passed; generated bundle consistency check passed.
- Browser suite: 28 passed, including two new aggregate public-website tests.

Browser checks use the actual PHP application with a disposable SQLite fixture and unreachable local API URLs. They cover all four anonymous routes, internal links, metadata, native sitemap, social image, existing account forms and stylesheet isolation, 404, 375px layouts, 200% scaling, keyboard menu/skip link, reduced motion, JavaScript errors, external requests and navigation without JavaScript. Text palette pairs meet 4.5:1 contrast. Existing dashboard browser tests remain green.

Desktop and mobile screenshots for every public page were visually reviewed. Evidence is stored outside source at `C:/projects/GoFormX/product/website-implementation/screenshots/`. No production enquiry was sent. Versioned source destinations were checked through GitHub; the personal-site proof link refers to the separate September 30 release evidence.

## Reproduce

Install locked dependencies with `composer install` and `npm ci`, then install the existing Playwright Chromium runtime with `npx --no-install playwright install chromium`. Run `composer check` after the repository's documented disposable database bootstrap. For screenshots, set `WEBSITE_EVIDENCE_DIR` to an output directory and run `node --test tests/Browser/website.test.mjs`.

The public controller takes its canonical origin from configured `APP_URL` through the existing API catalog configuration. Production requires an HTTPS origin; loopback HTTP is supported for local preview. It does not trust the request Host header. Native Waaseyaa SEO discovery receives the four paths through `PublicWebsiteSitemap`, without modifying framework code. `node scripts/render-website-og.mjs` regenerates the checked-in 1200x630 PNG from its SVG source.

## Qualified copy and remaining gates

The September 29 two-site local Codex rehearsal and September 30 live personal-site collection are separate evidence. Hosted client setup remains manual and is not qualified as a complete one-click published-client journey. Email notification delivery, a second assistant harness and broader production acceptance remain open. The getting-started commands are sourced from the versioned experimental client contract, with placeholder identities and explicit publication approval.

Operator identity is now confirmed as Russell Jones, jonesrussell42@gmail.com. The public-policy drafts in `docs/public-policies/` describe inspected retention, deletion and backup limits, with a separate evidence/decision plan. Proposed retention periods are not adopted or implemented. Illustrations are labeled synthetic and contain no real submissions or credentials. This change does not close product acceptance gates or authorize merges or deployment.

## Readiness follow-up

The release fixture repair at `10533fdf9fe2d840a6e78e1b56e8c4f47eac8175` pins custody discovery to the local test fixture. The complete hosted CI for #35 and refreshed website head `169cd5dc8b83f57123aaee1cdcacdbf7c86caf42` passed. Immutable deployed source remains bb384f90; these branch changes are not deployed.

Candidate client #206 at `b3b2d3347a7929efae61d349649867abcb9acdc1` passed hosted discovery, credential-free module generation, public schema read and allowed-origin preflight without a wrapper. Its short-lived forms:read grant was revoked (204), and the next call was denied (401). No enquiry or publication occurred. This qualifies the hosted read path, not the complete creation/publication/receipt journey. The compatibility page reflects that distinction. The client code is unchanged by its later documentation/CI update.
