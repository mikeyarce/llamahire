# LlamaHire testing guide

LlamaHire uses a fast WP-CLI contract suite plus a real-browser hiring workflow. Both run against an isolated WordPress site managed by `wp-env`; the CI configuration uses port `8897` so it does not reuse a developer's normal WordPress environment.

## Local prerequisites

- Node.js 20 or newer.
- Docker.

Install the locked dependencies and the test browser:

```sh
npm ci
npx playwright install chromium
```

## Run the suites

Start the isolated site and run the contract checks:

```sh
npm run env:start
npm run test:smoke
```

Run the browser workflow with deterministic disposable data:

```sh
npm run test:e2e:setup
npm run test:e2e
npm run test:e2e:cleanup
```

Stop the environment when finished:

```sh
npm run env:stop
```

The setup command always removes older browser fixtures before creating new ones. The cleanup command removes the test job, candidate record, and resume. The isolated site's browser credentials are `admin` / `password`; they are reset only inside this disposable environment and are not connected to a developer's local site credentials.

## Continuous integration

The GitHub Actions workflow runs:

- PHP syntax checks on PHP 7.4, 8.1, 8.3, and 8.5.
- The 61-check smoke suite on minimum WordPress 6.5/PHP 7.4, latest WordPress on PHP 7.4 and 8.5, and WordPress trunk/PHP 8.5.
- The complete Chromium workflow on latest WordPress/PHP 8.3.

WordPress trunk is informational and allowed to fail so upstream changes are visible without blocking a release. All declared supported versions are blocking. Browser traces, screenshots, video, and the HTML report are retained when a test fails.

The browser workflow verifies:

1. Structured job fields render in the editor, save, and survive reload.
2. Public job facts match `JobPosting` JSON-LD.
3. A candidate can submit a PDF resume.
4. A recruiter can update status and private notes.
5. Authorized protected resume download succeeds.
6. CSV export contains the application and neutralizes formula-like content.
