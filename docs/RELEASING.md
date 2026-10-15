# Releasing LlamaHire Free

Publishing a GitHub release packages the free plugin, attaches the ZIP and
checksum to the release, and deploys the same files to WordPress.org.

The workflow in `.github/workflows/release.yml` uses
[10up's WordPress.org Plugin Deploy action](https://github.com/10up/action-wordpress-plugin-deploy/tree/2.3.0),
pinned to version `2.3.0`. It copies the verified `dist/llamahire/` package into
SVN `trunk`, copies trunk to `tags/<version>`, and publishes `.wordpress-org/`
artwork to SVN `assets` in a single commit. This follows the
[WordPress.org Subversion guide](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/).
Development files and ZIP archives are never copied into SVN.

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
5. Confirm the final WordPress.org listing artwork in `.wordpress-org/`.
   Those files are separate from the plugin's runtime `assets/` directory; see
   [`WORDPRESS-ORG-ASSETS.md`](WORDPRESS-ORG-ASSETS.md).
6. Confirm the approved SVN URL is
   `https://plugins.svn.wordpress.org/llamahire/`. If the approved slug differs,
   update `SLUG` in the workflow before publishing.

## Preview the first upload

1. Merge the workflow onto GitHub's default branch so **Run workflow** appears.
2. Push a version tag pointing at the release commit, such as `v0.1.0`, with
   all three version fields matching. A tag push alone does not publish.
3. In **Actions → Release free plugin → Run workflow**, select the default
   branch and enter that existing tag.
4. Review the checks and the final deployment step's SVN status output. The
   manual run uses 10up's `dry-run` mode when `listing_only` is left unchecked:
   it prepares trunk, the version
   tag, and listing artwork locally on the runner, but never commits to SVN
   or attaches files to a GitHub release. It uses placeholder credentials, so
   SVN secrets and the deployment switch are not needed for this preview.

The approved public SVN repository must already exist for the preview to work.
Environment protection rules, if configured, also apply to manual runs.
For the initial upload, publish a non-prerelease GitHub release using the
previewed tag after completing the one-time setup above.

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

Runs are serialized across versions so two releases cannot update SVN trunk
at the same time. Publish versions in order. An already-existing SVN version
tag is skipped by 10up's action; publish a new version to change shipped code.
If the GitHub release was published before deployment was enabled, enable it
and re-run that release's workflow from Actions.

## Update listing text and screenshots

Readme and screenshot changes do not require a new plugin version. Commit and
push the reviewed changes, then run **Release free plugin** from that branch,
enter the current release tag, and set **listing_only** to true. This mode is a
live deployment, unlike the default release preview. It uses the existing
`wordpress.org` environment credentials and requires deployment to be enabled.

The listing job checks that the readme retains the published stable version,
updates only `trunk/readme.txt`, the current stable tag's `readme.txt`, and the
three `.wordpress-org/screenshot-N.jpg` files in SVN `assets`. It does not build,
publish, or replace plugin runtime code or GitHub release packages. Review the
SVN commit revision in the run output; directory caches may take time to refresh.
