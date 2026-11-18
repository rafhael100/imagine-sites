# Private technical release preparation candidate

This explicit CLI prepares a reviewable package from an existing Sites registry binding and the exact HTML bytes referenced by that binding. It does not create projects, grants, artifacts, editorial reviews, commercial evidence or customer acceptance. It does not publish, call Portal or promote `sites_preview` demonstrations.

`SiteReleasePreparation` uses `ReleasePreviewPolicy`, `InertPreviewAudit` and `PgSiteCommercialReleaseBindingSource`. The command and release in the input are selectors. The SQL source independently checks the current core project, contract, immutable artifact, latest editorial revision, binding revision and current principal epochs. Preparation reads this source twice and refuses a change or revocation between the reads. The HTML must also use the inert renderer dialect for the recorded product. The three output files preserve the HTML unchanged.

## Input and custody

The candidate image must contain `ops/release-candidate/prepare.php` beside the existing source and Composer autoloader. It is invoked explicitly and is not registered in a route, service provider, worker or container startup.

| Fixed path | Content |
| --- | --- |
| `/run/secrets/release_preparation` | Input JSON based on `manifest.example.json`, at most 16 KiB |
| `/run/secrets/sites_release_reader` | Password of the separately provisioned registry reader, 16–4096 bytes |
| `/run/secrets/sites_release_ca` | Reviewed PostgreSQL CA PEM, at most 64 KiB, matching `target.caSha256` |
| `/run/sites-release-input/index.html` | For `prepare`: exact registered artifact, at most 150000 bytes; `verify` reads the existing package instead |
| `/var/lib/imagine-sites/release-candidates` | Existing output parent, mode `0700`, owned by the CLI effective user |

Input files must be regular single-link files, mode `0400` or `0600`, owned by root or the effective CLI user. They must be readable by that user. Symlinks, including symlinked ancestors, are refused. The example intentionally contains invalid placeholders and cannot be run unchanged. No password, CA path, HTML path or output path is accepted in JSON or on the command line. Unknown fields, duplicate JSON keys, coercible strings in integer fields and unsafe references are refused. `packageRef` is a new directory name consisting of 1–64 letters, digits, underscores or hyphens, starting with a letter or digit.

The named SQL login must already have the current `reader` registry duty and its existing execute-only grant. This command grants nothing. Root/superuser, `SET ROLE`, reused transactions, excessive table/function rights and incompatible runtime states are refused by the existing registry guards.

## Target verification and execution

The staging path requires `APP_ENV=staging`, no CI opt-in, and `target.database=sites_staging`. The target hostname, port, database, database OID, login, observed server address and CA fingerprint must be supplied from separately reviewed environment inventory. The port must match the server-observed port; this candidate expects a direct database endpoint, not a differently numbered forwarded port.

```sh
php /opt/imagine/ops/release-candidate/prepare.php prepare
```

To check an already exported package against the current registry, use the same reviewed input JSON and its existing `packageRef`:

```sh
php /opt/imagine/ops/release-candidate/prepare.php verify
```

Verification reads exactly the three private files under that package directory. It does not need the original HTML input mount. `SiteReleasePackageVerification` reconstructs the expected package from its existing HTML, the selectors and the current SQL binding, using the same two independent reads as preparation. It compares every file byte for byte, including the complete manifest and readiness report. The package's own JSON never becomes an authority source. A missing/extra file, changed permissions, link, altered byte, stale binding revision, revoked association or reopened editorial revision is refused. Reassociating the same HTML produces a new binding revision and requires a new package; it never silently renews an old export.

The `verify` operation does not create directories, rewrite files, normalize JSON, repair metadata or write SQL. It rereads the package before reporting success and preserves its bytes, inodes and modification timestamps. A successful result has status `verified_current_internal_package`; publication and order flags remain false. This is a current technical check only and can become stale after the operation returns.

The command opens a fresh nonpersistent PDO connection with `sslmode=verify-full`, the fixed CA mount, GSS encryption disabled and bounded connection/query/lock timeouts. It makes the session read-only. It verifies PostgreSQL18, TLS1.2/1.3, the current database OID, login, server address and port before preparation and again before writing files. Target identity verification only queries catalog information and its own `pg_stat_ssl` row; it does not require `pg_control_system()`, `pg_monitor` or additional grants. A CA, hostname or target mismatch is a refusal. TLS is never downgraded for a test.

Successful stdout contains only status, artifact/package digests, revision numbers and false publication/order flags. Failure emits only `RELEASE_PREPARATION_DENIED` with a nonzero exit status. Briefs, HTML, order/customer references, paths, credentials and PDO diagnostics are not printed.

## Output and interpretation

The output is a new directory under the fixed parent, named by `packageRef`. It contains exactly:

- `index.html`: the input artifact, byte for byte.
- `release-manifest.json`: registry-derived references, exact digest and size, editorial/binding revisions and explicit limitations.
- `readiness.json`: the technical checks completed and the independent evidence not evaluated.

Files are created as `0600` inside a `0700` staging directory. After writing, syncing and rereading the exact bytes, the command exclusively reserves the new destination directory and atomically renames the complete staged tree over that own empty reservation. A pre-existing file, directory or symlink at the destination is refused; rerunning the same `packageRef` never replaces it. An interrupted process may leave a private staging directory or empty reservation requiring operator inspection; it does not silently repair or overwrite it. The package digest is SHA256 of `index.html`, NUL, `release-manifest.json`, NUL, `readiness.json`, in that order.

The manifest keeps `publishable`, `officialClientAcceptance` and `commercialAuthorityVerified` false. The readiness report keeps `canPublish` and `canAcceptRealOrders` false, with `independentEvidence=not_evaluated`. These describe what this package establishes, not a verdict from Portal or a PSP. An internally approved revision is not customer approval. Package bytes and metadata cannot authorize any subsequent action; a downstream consumer must revalidate the current binding, commercial evidence, explicit customer acceptance and its own operational gates. A reopening or revocation after export can make this package stale.

## Isolated CI path

The only alternative environment requires all three values `GITHUB_ACTIONS=true`, `SITES_RELEASE_PACKAGE_CI=1` and `APP_ENV=testing`. It additionally requires `host=localhost`, port `55449`, database `postgres`, login `rr_a_reader` and observed server address `127.0.0.1`. The database OID and CA hash must still match the disposable target. The fixed private files, file permissions, existing restricted reader, current SQL binding and `verify-full` checks remain mandatory.

The focused offline test covers parsing, file custody, exclusive output, complete package reading and default refusal; it does not claim a successful SQL authority check. The separate PostgreSQL contract fixture exercises preparation using the real renderer, registry and reader. The CLI fixture also verifies an export, rejects changes to each of its three files, and checks real revocation, reassociation and editorial reopening. A full CLI success requires the TLS-enabled disposable runner and its private files; neither offline test nor a component-only success substitutes for that execution. This directory adds no deployment authorization or production evidence.

## Explicit artifact registration candidate

The separate `register.php` command records exact inert HTML bytes for an existing Sites project through the existing `record_release_artifact` SQL entry point. It has its own manifest, private mounts and write confirmation. It creates no migration, SQL role, grant, project, binding, editorial transition or publication. It does not call a provider, accept payment or establish customer acceptance. It is invoked explicitly; no route, worker or container startup runs it.

### Registration input and custody

| Fixed path | Content |
| --- | --- |
| `/run/secrets/release_registration` | Exact `SITES_RELEASE_REGISTRATION_V1` JSON, at most 16384 bytes, based on `registration.example.json` |
| `/run/secrets/sites_release_artifact_writer` | Password of the existing restricted artifact writer, 16–4096 bytes |
| `/run/secrets/sites_release_registration_ca` | Reviewed PostgreSQL CA PEM, at most 65536 bytes, matching `target.caSha256` |
| `/run/sites-release-registration/index.html` | Exact HTML to register, 1–150000 bytes, valid UTF-8 without NUL, matching `artifactDigest` and `sizeBytes` |

The registration manifest contains exactly `schema`, `target`, `siteRef`, `releaseRef`, `artifactDigest` and `sizeBytes`. Its target contains exactly `host`, `port`, `database`, `databaseOid`, `login`, `serverAddress` and `caSha256`. The site UUID and opaque release reference select an artifact; they do not establish tenant authority. Duplicate keys, extra fields, unsafe coordinates and coerced integer values are refused. The example contains invalid placeholders and cannot be executed unchanged. No command accepts an input path, output path, password, CA override or alternative SSL mode.

All four files use the existing private-file custody checks: canonical paths, no symlinks or hard links, regular files with mode `0400` or `0600`, and root or the CLI effective user as owner. Registration does not create, repair, rewrite or chmod an input. The wrapper records bytes, SHA-256 and file identity, then rereads all four files after opening the connection and calculating the plan. It refuses any observed change in bytes, digest, inode, ownership, mode, link count, size or modification metadata before calling the mutating service. Access time is excluded because reading can update it. The in-memory HTML is never normalized or replaced.

### Plan, review and record

Staging requires `APP_ENV=staging`, no GitHub Actions or registration CI opt-in, and `target.database=sites_staging`. The reviewed target must describe a direct PostgreSQL endpoint: observed port, server address, database OID and login must match. A fresh nonpersistent PDO connection uses the fixed CA, `sslmode=verify-full`, `gssencmode=disable`, a 5-second connection timeout, a 10-second statement timeout and a 3-second lock timeout. It uses `pg_catalog`, UTF-8 and read committed isolation. The service observes PostgreSQL 18, TLS, target identity and effective entry-point permissions; the existing SQL function independently enforces its current principal, epoch, project and tenant checks when recording.

First obtain and review the concrete plan:

```sh
php /opt/imagine/ops/release-candidate/register.php plan
```

The plan uses a read-only session and changes no SQL data. Its result has schema `SITES_RELEASE_REGISTRATION_PLAN_V1`, status `planned`, `planSha256`, `artifactDigest`, `sizeBytes`, `canPublish=false` and `canAcceptRealOrders=false`. The plan hash binds the canonical manifest and exact HTML bytes. A successful plan is not SQL authorization, a reservation, payment evidence, customer acceptance or proof that a later record will succeed. Principal and database conditions can change after planning.

Only the reviewed hash and the exact registration flag allow the write command:

```sh
php /opt/imagine/ops/release-candidate/register.php record \
  --plan-sha256=REPLACE_WITH_REVIEWED_64_CHARACTER_LOWERCASE_PLAN_SHA256 \
  --authorize-record=SITES_RELEASE_ARTIFACT_ONLY_APPROVED
```

Both options are mandatory, may appear in either order and may appear only once. `plan` accepts no options. The write flags of other candidates do not authorize registration. The wrapper recalculates the plan on a read-only connection, checks the supplied hash and rereads the inputs. It then disables read-only mode only in that new candidate session and invokes `record` once. The service revalidates the exact plan and runtime before the existing SQL operation. It does not retry automatically.

Successful stdout is one JSON line with schema `SITES_RELEASE_REGISTRATION_RESULT_V1`, status `recorded`, the same digest, size, plan hash and false authority flags, plus `created`. Existing SQL idempotence returns `created=false` only for the same tenant/site/release identity, exact bytes, SQL author and current authorization epoch. Different bytes, another author or an epoch change do not silently replace an artifact. The `created` flag reports artifact insertion only; it confers no binding, editorial approval or permission to publish.

Failures emit only `RELEASE_REGISTRATION_DENIED` on stderr, with exit status 1 and no successful JSON result. HTML, credentials, paths, site/release references and PDO diagnostics are not printed. A failure during or after the SQL call, including a lost response, may follow a committed artifact. The CLI does not claim that every failure rolled back.

This command offers no artifact lookup or built-in diagnosis after a lost result. The publication-journal inspector reads existing journal operations; it cannot determine whether an unbound artifact registration committed. Any diagnosis requires separately authorized access outside this command. Any exact manual repetition must be an explicitly authorized decision using the existing SQL idempotence rules, with the same identity, bytes, author and epoch. An error does not establish that the artifact is absent; never automate a retry from the exit status alone.

### Registration CI boundary

The isolated alternative requires `GITHUB_ACTIONS=true`, `APP_ENV=testing` and `SITES_RELEASE_REGISTRATION_CI=1` together. It fixes `host=localhost`, port `55449`, database `postgres`, login `rr_a_artifact_writer` and server address `127.0.0.1`. The real database OID, CA fingerprint, file custody, plan hash and write confirmation remain mandatory. Offline CLI tests exercise help and early argument/environment refusals with the autoloader and private paths inaccessible; only the disposable PostgreSQL/TLS integration fixture can demonstrate actual registration. This candidate adds no deployment or operational authorization.
