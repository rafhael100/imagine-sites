# Sites486 — non-login owner and segregated SQL profile contract

Status: LOCAL PATCH / SQL TEST NOT EXECUTED / NO-MERGE / NO-DEPLOY.

## Provenance and non-duplication

Base #486 HEAD `a4db62ac07566fb6b0bd9a27988c2ad4c0622c56`; functional base
`ed9bac73ba291fc509bf4b5365451ea1a31032b6`. Rechecked unchanged before delivery.
The newest open Sites PRs were #486, #485, #484; #486 comments/reviews returned
empty. No equivalent was found in the inspected preview tests. This is separate
from #485's evidence-verifier contract and does not reuse its CI as a result.

Read AGENTS.md. No .agents/skills files were found in the checkout or inspected
workspace ancestor .agents directories. The HTTP fixture currently runs schema
SQL as postgres and gives all three principals can_review=true. This new test
leaves that fixture untouched and adds the missing owner/profile matrix.

## Intended assertions — NOT runtime results

- Bootstrap the real Laravel application, set a dedicated connection, verify its
  effective configuration and SQL database, session/current login, PG18 version,
  cluster system identifier, exact new data_directory and 127.0.0.1:55448 target.
- A mismatched expected cluster must exit nonzero before writes. The subsequent
  preflight-only call must still find no sites_preview namespace or fixture roles.
- Only then create a lab-only NOLOGIN/NOSUPERUSER/NOBYPASSRLS owner, temporarily
  grant database CREATE, SET ROLE to that owner and call the unchanged migration
  2026_11_16_000004 up(). Reset the migrator and withdraw its temporary CREATE grant.
- Verify schema, five tables, audit sequence and all five functions have that
  owner; four scoped data tables retain ENABLE/FORCE RLS. The principals table is
  authority metadata protected by ACL, not falsely counted as FORCE RLS.
- Create author/reviewer/reader native SQL logins for each of two synthetic
  companies, with no owner membership or administrative flags. Only authors get
  project/version INSERT; only reviewers get review_version EXECUTE. Readers get
  scoped SELECT and can_access only. Capability rows reflect these same profiles.
- Check effective table, function, sequence, schema and database privileges,
  PUBLIC ACLs, security-definer search paths and exact owner identities.
- Explicitly record the migration's existing PUBLIC EXECUTE on the trigger helpers
  check_writer_identity and immutable. Do not change or exercise those helper
  grants, or claim all functions have PUBLIC revoked. Schema PUBLIC grants are absent.
- Use PrivatePreviewWorkspace for normal creation, viewing, revision and review.
  Confirm own-company allowed operations, refusals for non-author/non-reviewer
  profiles and the other company, audit records, and no inherited review on v2.

No migration, authorization logic, concurrent revocation semantics or rollback
implementation is modified. No down()/restore is run. These fixtures are not
backup evidence, real IAM, customer approval, settlement or publication.

## Local environment and commands actually run

Executor: /workspace/scratch/c24b1cbd1d14. Existing unpacked PHP8.4 and PostgreSQL
18.7 (Debian package 18.7-1.pgdg13+2) under p20-tools. No system installation.

Passed:

```sh
p20-tools/bin/php -l sites486-role-contract/tests/private-preview-role-contract.php
bash -n sites486-role-contract/tests/run-private-preview-role-contract.sh
LD_LIBRARY_PATH=/workspace/scratch/c24b1cbd1d14/p20-tools/root/usr/lib/x86_64-linux-gnu \
  p20-tools/root/usr/lib/postgresql/18/bin/postgres --version
p20-tools/bin/php sites486-role-contract/tests/run.php
git -C sites486-role-contract diff --check
```

The domain suite reported 32 synthetic checks passed. It does not validate this
new SQL contract. The earlier task's local PostgreSQL socket creation failed with
Operation not permitted. No new service start, migration, role grant or SQL test
was attempted here; a TCP retry was not used to circumvent that restriction.
No new workflow was added, nor an existing workflow changed. No PR was opened
as validated. HTTPS publishing remains unavailable without credentials, which
were not changed.

## Next permitted execution

On a separate authorized laboratory host allowing loopback PostgreSQL, with
existing PG18/PHP and the locked Sites dependencies available:

```sh
PG_BIN=/path/to/postgresql/18/bin PHP_BIN=/path/to/php \
  bash tests/run-private-preview-role-contract.sh
```

The runner uses a new /tmp/sites486-role-contract-* cluster with a generated
local-only password, SCRAM on 127.0.0.1, no Unix socket or remote bind. Occupied
port fails; it never reuses/stops another service. It records logs only in its
private temporary directory and stops its own cluster on exit. Its preflight
must pass before migration or fixture writes. Review the success marker and
assertion count before claiming this candidate passed or opening a validated
draft. Do not enable this in an existing workflow without an authorized change.

## Approved isolated draft workflow — subsequent handoff

Owner approval was verified against request Sentinel_cd4244aec52481919756b93b9fee5aab
and response Sentinel_ff14415413748191a866a002588617ba. The new workflow is
.github/workflows/sites-preview-role-contract.yml. The earlier no-workflow statement
above describes the initial patch, before this specific authorization.

Only complementary draft PRs from the exact same-repository head branch
`test/sites486-preview-role-contract` targeting
`feature/sites-private-preview-http-2026-11-16` are eligible. There is no push,
workflow_dispatch, pull_request_target or main trigger. It does not execute on
the original #486 head. Permissions are contents:read and checkout does not
persist credentials. Standard ubuntu-24.04 runner; 10-minute timeout.

Official authenticated PG18 extraction is reused from #485
`e62f92f7a5dce40de22d03b3bc4d2457ac434115`, changing only the temporary directory
label. No global package installation or operational secret. The unchanged test
and runner enforce effective Laravel/SQL identity preflight and wrong-cluster
refusal before roles/schema writes; only a new loopback disposable cluster is used.

New YAML and all embedded Bash blocks parsed; test PHP and runner Bash syntax
passed. Prior 32 domain checks remain applicable because their code is unchanged.
No SQL result from this branch is yet confirmed. Publish as DRAFT with validation
pending, then inspect preflight, role/ACL/RLS assertions and cleanup before claiming
pass. A successful #485 run is tooling provenance, not evidence for this test.
