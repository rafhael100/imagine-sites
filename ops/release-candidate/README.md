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

The isolated alternative requires `GITHUB_ACTIONS=true`, `APP_ENV=testing` and `SITES_RELEASE_REGISTRATION_CI=1` together. It fixes `host=localhost`, port `55449`, database `postgres`, server address `127.0.0.1`, and login `rr_a_artifact_writer` or `rr_registration_a_artifact_writer`. The second login is the existing active synthetic author used by the combined fixture after it revokes the first author. This CI allowlist does not renew the revoked actor or establish either actor's SQL authority. Both names retain the same custody, target, TLS, current principal/epoch, plan and write-confirmation checks; staging behavior is unchanged. Offline CLI tests exercise help and early argument/environment refusals with the autoloader and private paths inaccessible; only the disposable PostgreSQL/TLS integration fixture can demonstrate actual registration. This candidate adds no deployment or operational authorization.

## Explicit release binding and revocation candidate

The separate `binding.php` command connects an existing immutable artifact and the current internal editorial approval through `bind_release_artifact`, or revokes a registry association through `revoke_release_binding`. It uses the existing SQL entry points and separately provisioned actors. It creates no migration, role, grant, project, artifact or editorial approval. It does not call a provider, prepare a package or establish payment, customer acceptance or publication authority.

### Binding input and action-specific custody

| Fixed path | Content | Used by |
| --- | --- | --- |
| `/run/secrets/release_binding` | Exact `SITES_RELEASE_BINDING_V1` JSON, at most 16384 bytes, based on `binding.example.json` | Both actions |
| `/run/secrets/sites_release_binding_ca` | Reviewed PostgreSQL CA PEM, at most 65536 bytes, matching `target.caSha256` | Both actions |
| `/run/secrets/sites_release_binding_writer` | Existing binding writer's password, 16–4096 bytes | `action=bind` only |
| `/run/secrets/sites_release_binding_revoker` | Existing revoker's password, 16–4096 bytes | `action=revoke` only |

Each execution reads exactly the manifest, CA and password selected by the manifest action. `plan` follows that same selection; it does not require or open the other password. The wrapper first reads and validates the manifest. A `bind` or `revoke` command that differs from its `action` is refused before opening either password or a PDO connection. The CA fingerprint and target constraints are also checked before reading the selected password.

The common manifest keys are exactly `schema`, `target`, `action`, `siteRef` and `expectedBindingRevision`. The target contains exactly `host`, `port`, `database`, `databaseOid`, `login`, `serverAddress` and `caSha256`.

| Action | Additional manifest keys | Revision inputs on 64-bit PHP |
| --- | --- | --- |
| `bind` | `releaseRef`, `editorialRevision` | `expectedBindingRevision`: 0 through 9223372036854775806; `editorialRevision`: 1 through 2147483647 |
| `revoke` | None; `releaseRef` and `editorialRevision` are refused | `expectedBindingRevision`: 1 through 9223372036854775806 |

All revision values are JSON integers, without coercion. A site UUID and opaque release reference are selectors, not tenant authority. Duplicate keys, extra fields and unsafe target coordinates are refused. The example intentionally contains invalid placeholders and cannot be executed unchanged. To describe revocation, use the common keys with `action=revoke`, the reviewed revoker target and a positive expected binding revision; remove both bind-only keys.

This command takes no HTML input, `expectedDigest`, supplied artifact digest, output path or commercial authority claim. For bind, the existing SQL function derives the artifact digest from the stored artifact and requires it to match the current internally approved editorial revision. The binder cannot supply replacement bytes or override that relationship.

Each of the three selected files must satisfy the existing private-file custody checks: canonical path, no symlinks or hard links, regular file with mode `0400` or `0600`, and root or the CLI effective user as owner. Files must be readable by that user. The command records bytes, SHA-256 and identity, then rereads those same three files after connection and planning. Any observed change in bytes, digest, inode, ownership, mode, link count, size or modification metadata is refused before the write. Access time is excluded. The command does not create, repair, rewrite or chmod inputs.

### Target, read-only plan and explicit write

Staging requires `APP_ENV=staging`, no GitHub Actions or binding CI opt-in, and `target.database=sites_staging`. Supply the target from separately reviewed environment inventory. The hostname must match the server certificate, and the direct endpoint's observed port, address, database OID and login must match the manifest.

A fresh nonpersistent PDO connection uses the fixed CA, `sslmode=verify-full`, `gssencmode=disable`, a 5-second connection timeout, `pg_catalog`, UTF-8, read committed isolation, a 10-second statement timeout and a 3-second lock timeout. It begins read-only. The service checks PostgreSQL 18, TLS, target identity and restricted effective execution capability for the selected action. The SQL function independently checks its current principal, duty, epoch, tenant and state when invoked; a manifest login name does not replace those checks.

First obtain and review the plan for the manifest action:

```sh
php /opt/imagine/ops/release-candidate/binding.php plan
```

The plan does not change SQL data, inspect a private binding head, establish a current principal duty or reserve an association. Its hash commits to the canonical manifest, including action, target, site, expected binding revision and the bind-only selectors when present. It does not claim that the project, artifact, approval or requested binding is currently available.

Plan stdout is exactly one JSON line with seven fields:

| Field | Value |
| --- | --- |
| `schema` | `SITES_RELEASE_BINDING_PLAN_V1` |
| `status` | `planned` |
| `planSha256` | Lowercase SHA-256 of the canonical plan input |
| `action` | `bind` or `revoke`, from the reviewed manifest |
| `expectedBindingRevision` | The supplied revision commitment; not a looked-up head |
| `canPublish` | `false` |
| `canAcceptRealOrders` | `false` |

For a reviewed bind manifest:

```sh
php /opt/imagine/ops/release-candidate/binding.php bind \
  --plan-sha256=REPLACE_WITH_REVIEWED_64_CHARACTER_LOWERCASE_PLAN_SHA256 \
  --authorize-bind=SITES_RELEASE_BINDING_ONLY_APPROVED
```

For a separately reviewed revoke manifest:

```sh
php /opt/imagine/ops/release-candidate/binding.php revoke \
  --plan-sha256=REPLACE_WITH_REVIEWED_64_CHARACTER_LOWERCASE_PLAN_SHA256 \
  --authorize-revoke=SITES_RELEASE_REVOCATION_ONLY_APPROVED
```

Each write requires exactly its two options, once each, in either order. The other action's flag or approval value is refused. `plan` accepts no options, and flags belonging to artifact registration or editorial transitions do not authorize a binding write.

The wrapper recalculates the plan on the new read-only connection, checks the supplied hash and rereads the selected inputs. It then disables read-only mode only in that session and invokes one `apply` call. The service revalidates the exact plan and runtime before using the existing bind or revoke API. It does not choose another revision, change credentials or retry automatically.

### Results, revocation scope and inconclusive outcomes

Successful bind stdout has exactly ten fields: the seven plan fields with `schema=SITES_RELEASE_BINDING_RESULT_V1` and `status=bound`, plus `bindingRevision`, `editorialRevision` and the registry-derived `artifactDigest`. Successful revoke stdout has exactly eight fields: the same seven fields with that result schema and `status=revoked`, plus `bindingRevision`. Both authority flags remain false. Site/release references, login names, paths, credentials and commercial identifiers are excluded.

The result has no `created` or `replayed` field. The existing SQL revision and repetition rules determine the returned binding revision. The CLI does not infer whether a returned revision was newly appended or represented an already matching event, and a success does not authorize an unreviewed repeat.

Revocation changes the registry association and makes a package tied to the old current association stale. It preserves stored artifacts, editorial history, binding history and previously exported package bytes. It does not unpublish a site at an external provider, retract customer consent or permanently prevent a later authorized bind. External publication, external rollback and future binding decisions require their own authorities and explicit actions.

Failures emit only `RELEASE_BINDING_DENIED` on stderr with exit status 1 and no successful JSON result. A failure during or after SQL, including a lost response or failed postcheck, can follow a committed event. The error does not establish rollback, artifact absence, creation or replay. Do not automatically retry or increment the expected revision. Resolve the outcome through separately authorized access to current registry state before deciding on another reviewed action. This command offers no private head lookup or built-in recovery procedure.

### Binding CI boundary

The isolated alternative requires `GITHUB_ACTIONS=true`, `APP_ENV=testing` and `SITES_RELEASE_BINDING_CI=1` together. It fixes `host=localhost`, port `55449`, database `postgres` and server address `127.0.0.1`. A bind manifest requires the synthetic target login `rr_a_binding_writer`; a revoke manifest requires `rr_a_revoker`. The selected password mount must match the existing actor. These pins constrain the disposable fixture; the SQL function still determines current authority and state.

The database OID, CA fingerprint, file custody, exact plan and explicit action-specific approval remain mandatory. The combined fixture may use the active `rr_registration_a_artifact_writer` through the real registration CLI to create its artifact, then separate editorial operators before binding. That CI-only registration allowlist does not restore the previously revoked author or relax any principal, epoch or tenant guard.

Offline CLI tests cover help and early argument/environment refusals with autoload and private inputs inaccessible. Actual bind/revoke, revision conflicts, repetition, isolation and package invalidation require the separate disposable PostgreSQL/TLS fixture. This candidate adds no deployment authorization or operational acceptance.

## Local HTML rendering from a private brief

The separate `render.php` command closes the local step before artifact registration: it reads one private technical briefing JSON and writes the existing renderer's exact inert HTML to a new `index.html`. It uses `SiteBrief`, `StaticSiteRenderer` and `InertPreviewAudit`; it preserves the renderer's demonstration disclaimer, escaped text and distinct Onepage/Landing Page layouts. It loads the Composer autoloader without booting Laravel and makes no database or network connection.

This is a local rendering input, not a versioned Portal briefing contract, official customer acceptance or commercial authorization. The command neither reads nor promotes a `sites_preview` project. It does not register an artifact, change editorial state, bind a release, prepare a registry-backed package or publish. Those existing commands and their separate authorities still apply to any later step.

### Briefing format and local paths

`render-brief.example.json` contains valid synthetic content. Prepare a private copy before using it; the repository example is not itself a private input. The JSON must be an object of at most 32768 bytes containing exactly these six fields:

| Field | Existing renderer requirement |
| --- | --- |
| `productCode` | String `onepage` or `landing_page` |
| `brand` | Nonblank UTF-8 string, at most 120 Unicode code points |
| `headline` | Nonblank UTF-8 string, at most 180 Unicode code points |
| `description` | Nonblank UTF-8 string, at most 600 Unicode code points |
| `callToAction` | Nonblank UTF-8 string, at most 80 Unicode code points |
| `benefits` | JSON list of 1–8 nonblank UTF-8 strings, each at most 180 Unicode code points |

NUL, duplicate keys (including escaped equivalents), additional fields, wrong types and unsupported products are refused. The command does not trim or normalize valid text. URLs and text that resembles markup or an event handler remain literal escaped content; they do not create active links, handlers or scripts. The audit checks the rendered HTML before any output file is created.

```sh
php /opt/imagine/ops/release-candidate/render.php --help

php /opt/imagine/ops/release-candidate/render.php render \
  --brief=/absolute/private/brief.json \
  --output=/absolute/private/output/index.html
```

The two paths above are illustrative. Supply existing, canonical absolute paths under custody of the invoking user. Both options are required exactly once, in either order; the output basename must be `index.html`. The command accepts no other option, environment selection or approval flag. This local reversible creation does not need a database target, mounted password or CI opt-in. `--help` works without loading the application autoloader or opening private files.

The briefing must be a readable regular file, 1–32768 bytes, with one hard link, mode `0400` or `0600`, and root or the effective CLI user as owner. The existing protected reader supplies the bounded read and descriptor checks. The local helper additionally checks every ancestor: canonical directories, no observed symlink component, root or effective-user ownership, and no group/other write permission. A root-owned sticky ancestor such as `/tmp` is permitted; an ordinary writable ancestor is refused.

The immediate output parent must already exist, be canonical, have mode `0700` and belong to the effective CLI user. The destination must be absent: existing files, directories, symlinks and dangling symlinks are refused. The command creates no directories, repairs no permissions and changes no input file. In particular, it does not overwrite an earlier `index.html` even when its bytes would be identical.

### Creation, result and failure handling

Before creating the output, the helper rereads the briefing and compares its exact bytes, SHA-256, inode, ownership, mode, link count, size, modification metadata and observed ancestor identities with the first read. Access time is excluded. An observed change is a refusal. The output is then created exclusively under a restrictive umask as a regular `0600` file. The helper compares the opened descriptor with the pathname, writes all original renderer bytes, flushes and synchronizes the file, and reads the bytes back before confirming its final size and custody.

These checks describe observed custody; PHP pathname operations do not provide an atomic descriptor-relative traversal of every ancestor. They do not promise protection from an undetectable concurrent change by the same trusted user or root. No failure path unlinks, renames or chmods an output. A failure after exclusive creation, a crash or a lost response can leave a new partial or complete private file. Inspect that path through authorized local access before deciding how to proceed; the command never removes it or silently retries.

Successful stdout is exactly one JSON line with seven fields:

| Field | Value |
| --- | --- |
| `schema` | `SITES_LOCAL_RENDERING_RESULT_V1` |
| `status` | `rendered` |
| `inputSha256` | SHA-256 of the original JSON bytes, including whitespace and a final newline if present |
| `artifactDigest` | SHA-256 of the exact HTML bytes written |
| `sizeBytes` | HTML byte count |
| `canPublish` | `false` |
| `canAcceptRealOrders` | `false` |

The result contains no path, briefing text, HTML, project reference or credential. Its hashes identify the observed local input and output; they do not certify a Portal revision or grant authority to register, approve or publish. Failure exits with status 1, empty stdout and only `LOCAL_RENDER_DENIED` on stderr. The error does not establish that output creation was rolled back.

The focused offline CLI tests execute both products under their own private `/tmp` directory without database credentials or a simulated CI environment. They compare the file with the direct renderer, verify input preservation, exercise the opposite option order and read-only input mode, refuse existing outputs and unsafe custody, and prove that a changed input snapshot cannot create output. The separate disposable registry fixture consumes the resulting file through the existing explicit registration flow; that integration does not make local rendering itself a database operation.
