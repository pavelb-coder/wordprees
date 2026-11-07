# WP Olympiad — project publishing rules

The repository owner requested ongoing synchronization of completed plugin work to `pavelb-coder/wordprees`, branch `main`.

- After each completed plugin change, update the plugin version and README/changelog, run the integration tests on a test WordPress instance, build the installable ZIP, and verify the installed ZIP before publishing.
- Commit and push the finished, tested changes normally. Fetch first, preserve other contributors' changes, and never use force-push. If branch protection prevents direct publication, use a pull request rather than bypassing protection.
- **Do not execute the historical `push-to-github.sh` helper**: it contains a force-push and an environment-specific path. The current publishing workflow uses explicit normal git commands, not this legacy script.
- Publish immutable versioned releases and the installation ZIP. Each release has identical assets `wp-olympiad-<version>.zip` and `wp-olympiad.zip`. Do not overwrite an existing tag or replace an asset with different bytes under the same version.
- A WordPress ZIP must contain the `wp-olympiad/` plugin directory. GitHub's source-code ZIP is not the installation package.
- Keep bundled editor assets byte-identical to their manifest unless intentionally updating and retesting the editor. Do not normalize their line endings.
- Workspace snapshots can roll back local git state: `origin` may disappear from `.git/config`, the `gh` binary may lose its executable bit, and the askpass helper may vanish. Symptoms are `fatal: 'origin' does not appear to be a git repository`, `Please make sure you have the correct access rights`, or `Permission denied` from `gh`. Fix it by running `/home/user/qa/restore-git-access.sh` (idempotent, contains no secrets) instead of pasting a token into the repository. Then `git fetch --tags --prune origin`, compare `git diff --stat origin/main`, and only then align the pointer with `git reset --mixed origin/main`; never `reset --hard` before that comparison. GitHub credentials stay in `~/.config/gh/hosts.yml` (mode 600, outside the repository) and are never committed.

- Never commit credentials, authentication configuration, wp-config.php, environment files, SQL backups, real NAS contents, participant data, real question/answer/protocol files, browser sessions, or test-server logs. Bundled fictional samples/tests/licenses are part of the source, not production data.
- Do not claim a push or release succeeded until the remote commit and downloaded asset hashes have been verified.
- This is a workflow for completed tasks, not an unattended background synchronization service. It does not deploy to the production WordPress site.

See [PUBLISHING.md](PUBLISHING.md) for download and release details.
