# Private editorial transition candidate

This explicit CLI requests one editorial revision for an existing Sites project through `sites_core.advance_editorial_revision`. It uses the existing SQL operator binding, tenant isolation, allowed transitions and expected-revision check. It adds no migration, role, grant, principal binding or automatic workflow. It is invoked directly, with the Composer autoloader; it does not boot the application or run from a route, worker or container startup.

An internal editorial approval does not establish customer acceptance, payment, delivery or publication authority. The command does not call Portal or a provider. Every successful result keeps `clientAccepted=false` and `canPublish=false`.

## Input and custody

The candidate image must contain `ops/editorial-candidate/transition.php`, `App\Editorial\PrivateEditorialTransition`, the existing source and the locked Composer dependencies.

| Fixed path | Content |
| --- | --- |
| `/run/secrets/editorial_transition` | Exact `SITES_EDITORIAL_TRANSITION_V1` JSON, at most 16384 bytes, based on `manifest.example.json` |
| `/run/secrets/sites_editorial_operator` | Password of the existing restricted editorial operator, 16–4096 bytes |
| `/run/secrets/sites_editorial_ca` | Reviewed PostgreSQL CA PEM, at most 65536 bytes, matching `target.caSha256` |
| `/run/sites-editorial-input/index.html` | Exact inert HTML under review, 1–150000 bytes, valid UTF-8 without NUL, matching `artifactDigest` and `sizeBytes` |

The manifest contains exactly `schema`, `target`, `siteRef`, `expectedRevision`, `action`, `artifactDigest` and `sizeBytes`. The target contains exactly `host`, `port`, `database`, `databaseOid`, `login`, `serverAddress` and `caSha256`. `expectedRevision` is an integer from 0 through 2147483646; numeric strings are refused. `siteRef` is an existing site UUID, and `artifactDigest` is the lowercase SHA-256 of the unchanged HTML bytes. Duplicate keys, extra fields and unsafe target coordinates are refused. The example intentionally contains invalid placeholders and cannot be executed unchanged.

No command accepts an input path, output path, credential, CA override, alternative SSL mode, action or revision override on the command line. Place the complete reviewed action in the private manifest. Neither a site selector nor a SQL login name supplied in that manifest grants tenant access or editorial authority.

All four files use the existing private-file custody checks: canonical paths, no symlinks or hard links, regular files with mode `0400` or `0600`, and root or the CLI effective user as owner. They must be readable by that user. This command does not create, repair, chmod or rewrite an input. It records each file's bytes, SHA-256 and identity, then rereads all four after connection and planning. Any observed change in bytes, digest, inode, ownership, mode, link count, size or modification metadata is refused before the mutating call. Access time is excluded because reading can update it. The in-memory HTML is preserved byte for byte.

The HTML input is audited and hashed; this command does not search the release registry for an artifact, record HTML there or bind a release. A later, separately authorized binding operation must establish the relationship among the immutable artifact, the current editorial revision and the release. Editorial success alone does not establish that such an artifact or binding exists.

## Existing transition rules

The action enum contains exactly the following values. This table describes the existing SQL duties; the PHP wrapper does not infer a duty from a login's name or choose an operator for the requested action.

| Action | Existing SQL duty | Editorial purpose | Resulting state |
| --- | --- | --- | --- |
| `register` | `editor` | Register the initial draft with expected revision 0 | `draft` |
| `revise` | `editor` | Append a different digest to the current draft | `draft` |
| `submit` | `editor` | Submit the current draft with the same digest for internal review | `internal_review` |
| `rework` | `reviewer` | Return an internal review to draft with the same digest | `draft` |
| `approve_internal` | `reviewer` | Record internal approval with the same reviewed digest | `awaiting_portal_acceptance` |
| `reopen` | `reviewer` | Reopen the current internal approval while preserving its reviewed digest | `draft` |

The database independently checks the current active operator binding, its tenant and duty, the project, the current revision, state and digest. The manifest does not provide `tenant`, `actorRole` or an approval override. A valid action string or a successful plan does not prove that the operator may perform that transition.

Reopening appends a new draft revision and invalidates the prior current release binding; it does not rewrite the historical approval or reuse that approval for a new digest. Further review and binding require their own authorized actions. Making an editorial operator inactive blocks new actions by that operator; it does not delete historical approval and is not a command to revoke a registry binding.

## Target verification and plan

Staging requires `APP_ENV=staging`, no GitHub Actions or editorial CI opt-in, and `target.database=sites_staging`. Supply the hostname, port, database OID, login, observed server address and CA fingerprint from separately reviewed environment inventory. The target must describe a direct PostgreSQL endpoint: the server-observed port must match the manifest port.

The wrapper creates a fresh nonpersistent PDO connection with `sslmode=verify-full`, the fixed CA mount, `gssencmode=disable` and a 5-second connection timeout. It sets read-only mode, `pg_catalog`, UTF-8, read committed isolation, a 10-second statement timeout and a 3-second lock timeout. The service checks PostgreSQL 18, TLS, target identity and the effective restricted editorial entry-point capability. It refuses privileged or incompatible runtime states and inherited transactions.

First obtain and review a concrete plan:

```sh
php /opt/imagine/ops/editorial-candidate/transition.php plan
```

The plan changes no SQL data. It does not call a private head or operator-duty lookup, establish the current editorial revision or reserve a transition. It verifies the input and runtime, then hashes the canonical manifest and exact HTML. Its deterministic hash includes the target, expected revision and action; changing any of them requires a new reviewed plan.

Successful plan stdout is one JSON line containing exactly eight fields:

| Field | Value |
| --- | --- |
| `schema` | `SITES_EDITORIAL_TRANSITION_PLAN_V1` |
| `status` | `planned` |
| `planSha256` | Lowercase SHA-256 of the canonical plan input and exact HTML |
| `action` | The reviewed action |
| `artifactDigest` | SHA-256 of the exact HTML |
| `sizeBytes` | Exact HTML byte count |
| `clientAccepted` | `false` |
| `canPublish` | `false` |

The plan is neither SQL authorization nor proof that a later action will succeed. The database's private binding, current state and revision are enforced only by the existing transition function when an apply is attempted.

## Explicit single apply

Only the reviewed plan hash and the exact editorial flag permit the apply command:

```sh
php /opt/imagine/ops/editorial-candidate/transition.php apply \
  --plan-sha256=REPLACE_WITH_REVIEWED_64_CHARACTER_LOWERCASE_PLAN_SHA256 \
  --authorize-transition=SITES_EDITORIAL_TRANSITION_ONLY_APPROVED
```

Both options are mandatory, may appear in either order and may appear only once. `plan` accepts no options. Flags belonging to artifact registration, preview provisioning or another candidate do not authorize an editorial write.

The wrapper recalculates the plan in its new read-only session, compares the supplied hash and rereads the four inputs. It then disables read-only mode only in that candidate session and invokes `apply` once. The service checks the exact plan and runtime before calling `advance_editorial_revision`. There is no batch, automatic follow-on transition or retry.

Successful apply stdout is one JSON line containing exactly ten fields: the eight fields above with schema `SITES_EDITORIAL_TRANSITION_RESULT_V1` and status `transitioned`, plus `revision` and `state`. The revision must equal the submitted `expectedRevision + 1`, and the state must match the requested action. Internal approval still returns both authority flags as false. Site IDs, login names, paths, credentials, HTML, commercial references and PDO diagnostics are excluded from stdout.

Failures emit only `EDITORIAL_TRANSITION_DENIED` on stderr with exit status 1 and no successful JSON result. A failure during or after the SQL call, including a lost response, can follow a committed editorial revision. The command does not promise that every failure rolled back.

**Editorial transitions are not idempotent.** Repeating an already committed request with the same expected revision conflicts with the current head. Never automatically retry or increment the revision after an error, timeout or lost response: doing so can request a different transition without reviewing its current state. This command offers no head lookup or built-in diagnosis. The separate [read-only inspector](#read-only-inspection-of-the-current-editorial-revision) can show the current head, but cannot attribute it to the lost call or authorize a retry. Resolve the outcome through separately authorized access to current editorial history before making a new explicit decision and reviewing a new plan. An error alone does not prove that no transition occurred.

## Isolated CI boundary

The only alternative environment requires `GITHUB_ACTIONS=true`, `APP_ENV=testing` and `SITES_EDITORIAL_TRANSITION_CI=1` together. It fixes `host=localhost`, port `55449`, database `postgres`, server address `127.0.0.1`, and login `rr_a_editor` or `rr_a_reviewer`. This allowlist identifies the two disposable test actors; it does not determine which actions either may perform. The database derives each actor's duty from its existing private binding.

The real database OID, CA fingerprint, file custody, exact plan hash and explicit write confirmation remain mandatory. Offline CLI tests exercise help and early argument/environment refusals with the autoloader and private paths inaccessible. Actual transition success, stale-revision conflicts, tenant isolation, restricted duty and operator deactivation require the separate disposable PostgreSQL/TLS fixture. This candidate provides no deployment authorization or evidence of operational acceptance.

## Read-only inspection of the current editorial revision

The separate `inspect.php` command reads the current editorial head of one known project through `sites_core.read_my_site_editorial_head(uuid)`. It uses a fresh PDO connection and the Composer autoloader without booting Laravel. It makes no transition, records no event, creates no output file and invokes no publication-journal API, Portal endpoint or provider.

This query helps an authorized operator inspect the current state before deciding what to do after a conflict or lost response. It does not identify which call produced that revision. A matching revision, state or digest is not proof that a particular timed-out transition succeeded, and a newer revision may include another operator's work. Inspection never authorizes retrying a transition or incrementing its expected revision automatically.

### Inspection manifest and custody

| Fixed private path | Content |
| --- | --- |
| `/run/secrets/editorial_inspection` | Exact `SITES_EDITORIAL_INSPECTION_V1` JSON, 1–16384 bytes, based on `inspection.example.json` |
| `/run/secrets/sites_editorial_inspection_reader` | Password of the separately provisioned restricted reader, 16–4096 bytes |
| `/run/secrets/sites_editorial_inspection_ca` | Reviewed PostgreSQL CA PEM, at most 65536 bytes, matching `target.caSha256` |

The manifest contains exactly `schema`, `target` and `siteRef`. Its target contains exactly `host`, `port`, `database`, `databaseOid`, `login`, `serverAddress` and `caSha256`. `siteRef` is a known project UUID. Extra fields, duplicate JSON keys, malformed references, coerced integers and unsafe target coordinates are refused. The example uses invalid placeholders and cannot be executed unchanged.

There is no HTML, expected revision, action, plan hash, tenant override, output path or acceptance claim in this input. A selector or target login does not establish tenant authority. The SQL function uses the current login's active read binding to decide access; an editorial writer or reviewer duty is not granted or inferred by the command.

Each input must be a readable regular file at a canonical absolute path, with no observed symlink component, one hard link, mode `0400` or `0600`, and root or the CLI effective user as owner. `SiteReleasePreparationFiles::read` checks the opened descriptor against the pathname and bounds each read. The wrapper records exact bytes, SHA-256 and identity for all three inputs, including the password. After the query, it rereads all three and refuses to release a result if bytes, digest, inode, ownership, mode, link count, size or modification metadata changed. Access time is excluded. It does not create, repair, rewrite or chmod any input.

### Connection and effective read capability

Staging requires `APP_ENV=staging`, no GitHub Actions or editorial-inspection CI opt-in, and database `sites_staging`. Obtain the hostname, port, database OID, login, server address and CA fingerprint from the reviewed environment inventory. The target describes a direct endpoint whose server-observed address and port match the manifest.

The nonpersistent PDO connection uses `sslmode=verify-full`, the fixed CA mount, `gssencmode=disable` and a 5-second connection timeout. The session remains read-only throughout, with `pg_catalog`, UTF-8, read committed isolation, a 10-second statement timeout and a 3-second lock timeout. The service verifies PostgreSQL 18, TLS, exact target identity, no inherited transaction and the restricted effective API capability before and after reading.

The reader requires schema USAGE and EXECUTE exclusively on `sites_core.read_my_site_editorial_head(uuid)`, without grant option or execution of other core/publication APIs. It does not receive direct table access, transition authority or a journal-reader capability. The new read entry point must already be installed through its separately reviewed migration, and the reader must already be provisioned. The CLI applies no migration or grant and changes no writer or existing status endpoint.

```sh
php /opt/imagine/ops/editorial-candidate/inspect.php --help
php /opt/imagine/ops/editorial-candidate/inspect.php inspect
```

`inspect` takes no options. `plan`, write operations, approval flags, credentials or path overrides are refused. `--help` works before autoload and without environment variables, private files or a database connection. No command selects another revision or retries automatically.

### Result and interpretation

Success is exactly one JSON line with seven fields:

| Field | Value |
| --- | --- |
| `schema` | `SITES_EDITORIAL_INSPECTION_RESULT_V1` |
| `status` | `inspected` |
| `revision` | Current positive editorial revision, or `null` when the authorized project has no editorial head |
| `state` | `draft`, `internal_review`, `awaiting_portal_acceptance`, or `null` with no head |
| `artifactDigest` | Digest recorded in that editorial revision, or `null` with no head |
| `clientAccepted` | `false` |
| `canPublish` | `false` |

For an authorized project without editorial history, `revision`, `state` and `artifactDigest` are all null together. An absent project, a project belonging to another tenant or an unauthorized reader receives the same bounded refusal instead. The service validates the returned project identity internally; it does not include it, an actor, tenant, credential, path or private diagnostic in stdout.

The returned digest describes the editorial head. Inspection does not prove that matching HTML exists in the artifact registry, that a release binding is current, that customer acceptance was verified or that anything was published. It does not consume a writer's plan or reservation. The result can become stale after the read, so a later explicit transition still needs its own reviewed inputs, expected revision and SQL authorization.

Failures return exit status 1, empty stdout and only `EDITORIAL_INSPECTION_DENIED` on stderr. The command exposes no exception chain or SQL diagnostic and never switches the session to writing. A refusal does not explain whether a project exists in another tenant or whether a previous transition committed. Use separately authorized investigation when that distinction matters; the current-head query alone cannot reconstruct the origin of an earlier revision.

### Inspection CI boundary

The disposable alternative requires all of `GITHUB_ACTIONS=true`, `APP_ENV=testing` and `SITES_EDITORIAL_INSPECTION_CI=1`. It pins host `localhost`, port `55449`, database `postgres`, observed address `127.0.0.1` and login `ei_a_reader`. The real database OID, CA, private-file custody, read-only session and exclusive read capability remain mandatory. This login pin identifies only the synthetic fixture actor; SQL still controls current read authority and tenant access.

Offline CLI tests cover help and bounded argument/environment refusals with autoload and all private inputs inaccessible. They fabricate no successful query. Current-head reads, null-head behavior, tenant isolation, reader deactivation, transport/target checks and unchanged rows/catalogs require the separate PostgreSQL/TLS fixture. This candidate creates no operational reader, installs nothing in staging and supplies no deployment or publication approval.
