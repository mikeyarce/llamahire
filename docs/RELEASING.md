# Releasing LlamaHire Free

Publishing a GitHub release packages the free plugin, attaches the ZIP and
checksum to the release, and deploys the same files to WordPress.org.

For the short setup and testing command reference, see
[`../CONTRIBUTING.md`](../CONTRIBUTING.md).

## One-time setup

After the plugin is approved in the WordPress.org Plugin Directory:

1. In the GitHub repository, create an environment named `wordpress.org`.
2. Add `SVN_USERNAME` and `SVN_PASSWORD` as environment secrets. Use the
   WordPress.org username with commit access and its SVN-specific password.
3. Add an environment variable named `WPORG_DEPLOY_ENABLED` with the value
   `true`. Until this variable exists, GitHub releases still receive their ZIP
   and checksum, but the WordPress.org deployment step is safely skipped.
4. Optionally add required reviewers to the environment if releases should
   require a final human approval before deployment.
5. Replace the temporary WordPress.org listing artwork in `.wordpress-org/`.
   Those files are separate from the plugin's runtime `assets/` directory; see
   [`WORDPRESS-ORG-ASSETS.md`](WORDPRESS-ORG-ASSETS.md).

## Cut a release

1. Choose a version such as `0.2.0`.
2. Update all three version fields:
   - `Version` in the `llamahire.php` plugin header.
   - `LLAMAHIRE_VERSION` in `llamahire.php`.
   - `Stable tag` in `readme.txt`.
3. Add the matching entry to the `readme.txt` changelog.
4. Merge the release changes and make sure CI passes.
5. Create and publish a GitHub release from that commit. Its tag may be
   `0.2.0` or `v0.2.0`.

Draft releases do not run the workflow, and published prereleases are skipped.
The workflow rejects non-version tags and mismatched version fields before it
touches WordPress.org.
