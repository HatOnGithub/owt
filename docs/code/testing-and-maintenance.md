# Testing and maintenance

Use the [development guide](../development.md) for prerequisites, detailed setup and CI notes. This page explains which checks protect which code contracts. Commands below run from the repository root unless a frontend working directory is shown.

## Test layers

Windows WebKit runs with one worker: concurrent instances can stall during document initialization before an application view loads. Chromium and Firefox, and WebKit on other operating systems, retain the normal worker limits. This keeps the same permission and workflow assertions active on every supported browser.

Prepare the pinned official barcode source cache with `python tests/dolibarr_compatibility.py --download --php /path/to/php` before running the gateway suite. `npm run test:barcodes` in `frontend` verifies decoder round trips independently of the native renderer. Barcode camera/browser tests also check unchanged IDs and carrier-specific layout geometry.

| Check                   | Command                                                                | What it establishes                                                                                                         |
| ----------------------- | ---------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| C# console suite        | `dotnet run --project tests/OwtApi.Tests -c Release`                   | Domain rules, independent grants, locations, history, repository migration/concurrency/rollback and operation recovery      |
| HTTP smoke              | `python tests/http_smoke.py`                                           | Real service HTTP contract, storage configuration, persistence and restart behavior                                         |
| PHP gateway integration | `python tests/angular_integration.py --php /path/to/php`               | Real C# plus PHP, native API doubles, CSRF, object scopes, stock transactions, receipts, permissions and public projections |
| Production browser      | Build frontend, then add `--browser` to gateway integration            | Built Angular served by PHP against the real service and native doubles                                                     |
| Native compatibility    | `python tests/dolibarr_compatibility.py --download --php /path/to/php` | Selected official Dolibarr source contracts and native behavior for explicitly listed versions                              |
| Mock API                | In `frontend`: `npm run test:mock`                                     | Development fixture/public contract and mock operations                                                                     |
| CSV helpers             | In `frontend`: `npm run test:csv`                                      | Parsing/export/import-related helper behavior                                                                               |
| Label model             | In `frontend`: `npm run test:labels`                                   | Standalone print detail selection/profile rules                                                                             |
| Browser type checking   | In `frontend`: `npm run typecheck:e2e:mock`                            | TypeScript mock browser fixture/test contracts                                                                              |
| Mock browser            | In `frontend`: `npm run e2e:mock -- --project=chromium`                | User flows, accessibility locators, capture, permissions, recovery notices and pending foldouts                             |

The C# tests are an executable assertion runner, not a `dotnet test` project. Gateway integration expects an appropriate Release service build. Portable Windows PHP can be prepared with `python tools/test-php-runtime.py`; then use `--php artifacts/php-runtime/php.exe --extension-dir artifacts/php-runtime/ext`. Use `npm.cmd` on Windows where script policy blocks `npm`.

Mocks do not prove real native transactions. Compatibility tests exercise selected official code against doubles, not a full deployed Dolibarr database. Printer, physical-camera and production deployment validation remain separate concerns.

## Browser isolation and useful focused runs

Mock Playwright tests use a session header to isolate each attempt's state and settings. Production browser tests intentionally share a seeded service/native fixture and run serially. Do not increase their parallelism without separate data directories, entities and native fixture stores.

Finish source/build changes before starting browser checks: Angular rebuild/live reload can interrupt a workflow. Avoid running production and mock browser suites concurrently when their output directories overlap; one runner can clear another's artifacts. Use `--grep` for a focused issue, then broaden only if the change affects more flows.

The Playwright mock server disables component HMR and live reload so navigation tests use stable modules. Permission scenarios that need a fresh bootstrap navigate to a distinct document; reloading immediately after a hash-route change can cancel a pending lazy import. After an action redirects, wait for the destination view before navigating again. Barcode decoder regressions include small labels, dark surrounds, limited video-range luminance levels and a captured grayscale scanline from our generated Code 128 label in Linux WebKit. Keep the surrounding pixels in that regression: cropping to white paper hides the threshold failure. Failed virtual-camera tests attach `camera-video-frame`, an unscaled PNG of the pixels presented to the scanner, because screenshots rescale the preview and can change the apparent bar widths.

Camera carrier tests use the shared Playwright timeout (60 seconds in CI), which includes page/context setup as well as the test body. Avoid overriding it with a shorter fixed limit: hosted Windows Firefox page setup and navigation can consume most of 30 seconds before a label is shown. CI runs each browser/OS pair in its own 15-minute job instead of fitting three engines into one job; Windows WebKit remains serialized within its job. Frontend type/model/decoder/CSV/mock checks run once in the core job. Wait for the destination page before asserting asynchronously loaded controls. The mock Firefox profile sets `privacy.bounceTrackingProtection.mode` to `0` so its fresh first-party localhost sessions do not emit native bounce-tracker warnings before the first automated interaction; application warning/error assertions remain active. See [Mozilla's preference documentation](https://firefox-source-docs.mozilla.org/toolkit/components/antitracking/anti-tracking/bounce-tracking-protection/index.html).

Branch pushes run the full suite in all three engines on Linux. Pull requests, version tags and manual runs cover all nine OS/engine combinations, so release publication still requires the full cross-platform matrix. Each job installs only its selected browser, and diagnostic artifact names include both OS and browser.

Playwright starts `dev/start-e2e.mjs`, which builds the mock configuration once and serves its bundles together with the isolated mock API. The server only serves enumerated regular files from that build; unknown assets retain a 404, and API requests retain their normal routes and permissions. This avoids the Angular dev server's repeated development-module requests and lazy compilation during tests. Interactive `npm run start:mock` keeps the development server and live updates.

For pending details, verify collapse/expand, per-QR stock effects, unavailable details and visibility without Recovery. Gateway tests must also inject raw private fields and hidden product/warehouse changes to establish that summaries never expose them.

## Changing a domain command

1. Decide which layer owns the new rule. Native access/facts belong in PHP; QR state and planning belong in C#; review/feedback belong in Angular.
2. Map the independent grant in the module descriptor, PHP, forwarded capabilities, C# and UI. Preserve native rights and check the exact referenced objects.
3. Choose the QR-only or recoverable native path. Persist intent before native writes, preserve receipt/evidence rules and recheck original grants during recovery.
4. Update explicit browser projections, public models and history summaries only with safe fields. Never solve missing UI data by returning internal state wholesale.
5. Add meaningful regression coverage for denial, stale state and failures at the affected boundary. A stock change needs rollback/lost-response behavior, not only a happy-path mock.
6. Update these documents, nearby comments, fixtures and user-facing guidance where behavior changes.

## Database changes

The EF model is `InventoryDbContext.cs`; migrations and snapshot are under `Migrations`. Restore local tools, change the model, scaffold and review a versioned migration:

```sh
dotnet tool restore
dotnet tool run dotnet-ef migrations add DescribeTheChange --project src/OwtApi
dotnet tool run dotnet-ef migrations has-pending-model-changes --project src/OwtApi
dotnet run --project tests/OwtApi.Tests -c Release
```

Do not replace migration-based initialization with `EnsureCreated`. Add preservation tests for data conversions, including old JSON import when relevant. The design-time factory uses an in-memory connection; manually applying migrations to a persistent database needs an explicit connection and should use a test copy. Do not remove applied migrations to make a deployment appear compatible.

Adding a document collection can require repository load/save changes even without an EF table change. Review default state, `Maps`, selected reads, validation and export round trips. Preserve the distinction between document schema and database migration versions.

## Build, package and release

```sh
python tools/build-frontend.py
dotnet build OpenWarehouseTracker.sln -c Release
python tools/package.py
```

The frontend tool first installs the exact locked dependencies using `npm ci --include=dev`, then compiles and deploys Angular and standalone print output into the module. This prevents stale `node_modules` after pulling updates; development dependencies are needed even when building on a production host. `--skip-install` reuses an installation from the current lockfile; `--skip-build` only deploys existing output and skips installation too. Installation and compilation must both succeed before deployed bundles are changed. The packaging tool checks licenses, generated print/SPA files and matching versions in the frontend package/lockfile, module descriptor and service project. It creates the versioned archive under `artifacts`; do not copy an outdated filename from a previous release.

Frontend/PHP formatting uses the repository Prettier configuration and PHP plugin; C# uses the configured CSharpier local tool. Format the files intentionally changed and use `git diff --check` to catch whitespace errors. Comments and docs should describe behavior and invariants rather than repeat obvious syntax.

Back up QR service state and Dolibarr data together before deployment. Preserve the service data directory outside the application installation, including required SQLite sidecar state in a coordinated backup. See [updating](../updating.md) for operational steps. Tests or a successful build do not authorize or perform a production deployment.
