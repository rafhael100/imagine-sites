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
| `/run/sites-release-input/index.html` | Exact registered artifact, at most 150000 bytes |
| `/var/lib/imagine-sites/release-candidates` | Existing output parent, mode `0700`, owned by the CLI effective user |

Input files must be regular single-link files, mode `0400` or `0600`, owned by root or the effective CLI user. They must be readable by that user. Symlinks, including symlinked ancestors, are refused. The example intentionally contains invalid placeholders and cannot be run unchanged. No password, CA path, HTML path or output path is accepted in JSON or on the command line. Unknown fields, duplicate JSON keys, coercible strings in integer fields and unsafe references are refused. `packageRef` is a new directory name consisting of 1–64 letters, digits, underscores or hyphens, starting with a letter or digit.

The named SQL login must already have the current `reader` registry duty and its existing execute-only grant. This command grants nothing. Root/superuser, `SET ROLE`, reused transactions, excessive table/function rights and incompatible runtime states are refused by the existing registry guards.

## Target verification and execution

The staging path requires `APP_ENV=staging`, no CI opt-in, and `target.database=sites_staging`. The target hostname, port, database, database OID, login, observed server address and CA fingerprint must be supplied from separately reviewed environment inventory. The port must match the server-observed port; this candidate expects a direct database endpoint, not a differently numbered forwarded port.

```sh
php /opt/imagine/ops/release-candidate/prepare.php prepare
```

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

The focused offline test covers parsing, file custody, exclusive output and default refusal; it does not claim a successful SQL authority check. The separate PostgreSQL contract fixture exercises preparation using the real renderer, registry and reader. A full CLI success requires the TLS-enabled disposable runner and its private mounts; neither offline test nor a component-only success substitutes for that execution. This directory adds no deployment authorization or production evidence.
