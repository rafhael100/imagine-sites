# Persisted preview → existing evidence verifiers: local contract candidate

DRAFT CANDIDATE / NOT DATABASE-VALIDATED / NO-MERGE / NO-PUBLISH.

Base: `7c2eb2ba005557023ba8cbee6869bbb156709cb8` (Sites #484 functional code).
At recheck #484 moved to `4b324c63b9450df780cc5be583a02113118aeb14`, documentation only.
No competing newer Sites PR was returned in the latest-open list.

The new test creates two synthetic demonstration products with the existing
PrivatePreviewWorkspace, records an independent SQL editorial review, and rereads
HTML/digest/revision through new workspace connections. It invokes the existing
IndependentPortalCommercialGateway, SignedPortalClientApprovalGate and
SiteDualProofEvidenceCheck used by F396/F397. No second decision engine, application
adapter, client acceptance source, route or provider is introduced.

The fixture matrix has 22 assertions: stored digest and review, absent commerce
and/or client consent, a positive synthetic evidence control, new persisted version
without inherited internal approval, refusal of old client consent on that new
version, and an old-exact-version positive control. Signed positive controls and
witnesses are explicitly synthetic and never operational authority. This does not
run the original Portal issuer or prove a Portal request/payment/preview journey.

## Local validation, 2026-11-16

- PHP 8.4 lint and Bash syntax: passed.
- Required `php tests/run.php`: 32 synthetic domain checks passed.
- Composer lock installed without scripts/plugins; no manifest or lock changes.
- Local PostgreSQL 18.7 launch attempted in a fresh private temporary directory,
  host authentication rejected and TCP disabled.
- Initial launch lacked liburing in the loader path; used the already-installed
  local library directory. Next launch failed: Unix socket creation returned
  `Operation not permitted`. PostgreSQL logged shutdown before accepting connections.
- Therefore the new 22 assertions, Laravel/SQL target preflight and migration were
  **not executed successfully**. No new persistence evidence is claimed, no mock
  substituted for the database, and no draft PR is opened as validated.

## Reproduce on a local executor permitting Unix sockets

Install this application's locked dependencies without scripts/plugins, then set
PG_BIN to the local PostgreSQL 18 bin directory and PHP_BIN to PHP 8.3+ with
pdo_pgsql, mbstring and sodium. Run:

```sh
PG_BIN=/path/to/postgresql/18/bin PHP_BIN=/path/to/php \
  bash tests/run-persisted-preview-contract.sh
```

If required by locally unpacked binaries, supply their library search path.
The runner creates its own cluster under /tmp/sites-preview-contract-*, observes
its system identifier using pg_controldata, and uses a private Unix socket only.
Before existing migration up(), the PHP test verifies effective Laravel config
and SQL database/login/data_directory/port/PG18/cluster/socket. A deliberately wrong
expected cluster must fail; a correct read-only pass then verifies the namespace
still absent before the normal test can write. Every run uses a new directory;
cluster is stopped on exit and logs remain local. No existing DB is reused.

Next action: execute that harness on an executor supporting local Unix sockets;
inspect the preflight marker and 22-assertion success marker before publishing a
complementary draft. Existing CI evidence remains scoped to #484, not this patch.

## Authorized CI handoff

The owner subsequently approved a new workflow dedicated to the complementary
draft PR. `.github/workflows/sites-persisted-preview-contract.yml` uses standard
ubuntu-24.04, contents:read, no operational secrets, exact PR head checkout and
a draft/head-repository/head-branch guard. It invokes the same local disposable
PG18 harness; no existing workflow was edited. The earlier local socket refusal
was not retried. CI has not run for this patch.

Public repository and disabled main ruleset reconfirmed. Sites #484 remains at
4b324c63b9450df780cc5be583a02113118aeb14, its documentation-only successor to the
functional base. No newer competing Sites PR appeared in the latest-open list.

HTTPS push failed before publication because no username was available with
terminal prompting disabled. Credentials were not changed. Publish the provided
patch through the coordinator's official connector on branch
`test/persisted-preview-evidence-contract-2026-11-16`, based on functional
7c2eb2ba005557023ba8cbee6869bbb156709cb8, and open a DRAFT targeting
`feature/sites-private-preview-workspace-2026-11-16`. Then confirm the exact
HEAD workflow, preflight and 22-assertion result; do not claim CI success before
those results.
