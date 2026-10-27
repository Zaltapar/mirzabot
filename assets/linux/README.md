# Linux release assets

Per-arch Linux bundles attach to the GitHub release (built by
`.github/workflows/release-linux.yml` on every `v*` tag):

- `mirzabot-linux-amd64-src-<ver>.tar.gz` — source tree + `run-bot.sh`
- `mirzabot-linux-arm64-src-<ver>.tar.gz` — same, for arm64 boxes

The launcher runs `table.php` with whatever PHP CLI is on the machine
(falls back to a bundled `./php` if present), so a target box still needs
a working PHP 8.2+ (or use the stock Apache/PHP-FPM deployment).

## Bundled static PHP binary (not wired up yet)

A self-contained bundle that ships its own static `php` binary is planned
(`mirzabot-linux-<arch>-<ver>.tar.gz`). The arm64 build path (spc on a
self-hosted ARM runner) is documented and ready to enable in
`release-linux.yml` (search for `OPT-IN`). The amd64 static-php source
URL still needs to be verified, so the binary-bundle build is intentionally
left out for now — every tag ships the source bundles above.
