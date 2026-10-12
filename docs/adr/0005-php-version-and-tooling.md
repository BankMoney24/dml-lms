# ADR 0005 — PHP 8.3 floor, 8.5 target; dev tools via Composer

**Status:** accepted · 2026-10-12

## Decision
* `composer.json` requires PHP ≥ 8.3 and pins the platform to 8.3.0 for dependency resolution, so one lockfile works on 8.3–8.5.
* CI runs the full suite on 8.3, 8.4 and 8.5. Production should use 8.5 (PRD §10.1) once the server's PHP build is confirmed by `deploy/server/inspect.sh`; then raise the floor here.
* No runtime Composer packages. Dev: PHPUnit 11, PHPStan 2 (level 8), PHP_CodeSniffer (PSR-12).
* Tests are written to run on PHPUnit 9.6–11 (no attributes) so they also run with distro packages.
* `composer.lock` is generated once by the *Generate composer.lock* workflow (the environment that wrote this code had no Packagist access) and then committed; CI refuses to run without it.
