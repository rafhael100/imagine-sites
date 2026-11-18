# Candidate only — authenticated persisted demonstration UI

NOT INSTALLED. Keep existing imagine-sites-p0-staging containers/networks/volumes/certificates/127.0.0.1:18090 intact until the gates below are individually evidenced and approved. No automated deploy, no Compose up/down, no migration here.

## Security / DBA review required before migration

Independent reviewer must inspect private-preview/001-workspace.sql and 002-review-notes.sql, migrations000004/000008, PrivatePreviewWorkspace, PreviewPrincipal, HTTP controller, routes, template and nginx candidate; attach exact SHA, findings and approval. Author self-review and CI are not independent approval. Verify owner/runtime separation, FORCE RLS, grants and SECURITY DEFINER ownership/search_path, immutable history, cross-company and revocation tests, SQL principal independence and HTTP trust boundary. Existing review #485 concerns evidence verification, not this UI deployment.

## Proposed controlled application, subject to approval

1. Read-only inventory of current stack IDs/images/networks/ports/volumes/migrations; record expected base SHA and image digest privately. No rebuild or recreation now. Existing active schema/roles remain untouched by this candidate.
2. Before any schema change: take a consistent encrypted Sites-only backup with existing tools, verify authenticity before decryption; restore into an isolated Sites-owned destination, verify row counts/hash/roles/RLS/TLS. Document custodian, retention, RPO/RTO and external copy. Historical local eight-table restore does not prove current full recovery. Do not reuse CRM/Portal credentials or data.
3. Review additive migrations000004/000008 and perform dry rehearsal against restored isolated copy. Read runtime checks before bootstrapping grants. No dropping/replacing existing schema. Existing data is never a rollback target for destructive down().
4. Named administrators must supply their own existing/approved private access using the secure custody procedure. Deploy-owned `/run/secrets/preview_principals` file (0400/0600, readable only by app user) schema SITES_DEMO_PRINCIPALS_V1 maps exact Nginx REMOTE_USER to a distinct SQL LOGIN, company UUID, sql_password, expires_at integer, revoked false. No concrete user/certificate/credential or operational order authority is created by this repository. Each human must have a separate SQL principal; map and immutable SQL login attribution are part of the audit evidence.
5. SQL principals need own scoped sites_preview.principals grant. Runtime minimal USAGE schema; SELECT projects/versions/reviews/audit; INSERT projects/versions for author only; EXECUTE can_access and review_version for authorized reviewer; no principals write, mutation, owner/BYPASSRLS/superuser or shared runtime account. Database grants enforce authority independently of browser fields.
6. Candidate config: APP_ENV=staging, APP_DEBUG=false, SITES_PRIVATE_PREVIEW_ENABLED=true only after gates; encrypted cookie session with SESSION_DRIVER=cookie, SESSION_SECURE_COOKIE=true, SESSION_HTTP_ONLY=true, SESSION_SAME_SITE=strict, app key from existing custody. All requests re-read nominal mapping expiry/revocation and DB grants; cookie contains no database authorization. No local/testing public bypass.
7. Review candidate nginx.conf separately. It preserves existing root/health and mTLS default-deny and adds only /_preview behind existing nominal Basic auth+TLS; REMOTE_USER and authenticated marker are server FastCGI params, never X-* user headers. Private PHP-FPM remains unexposed. No new host listener/DNS/public tunnel. CA/hostname validated, never ignore certificate warning.
8. Image candidate adds resources and existing contracts required by migration; no build/pull/replace on VPS before release plan approval. Exact selective update/reload strategy must preserve this environment; not an instruction to recreate it.
9. After approval, verify desktop/mobile: create two product types, briefing, persisted renderer output, version digest, new version, previous immutable version, different editorial reviewer, own-review/cross-company/revocation denied. Keep purpose demonstration_only; no payment/contract/acceptance checkbox fabricated.

## Rollback plan (reviewable; not executed)

Disable preview feature and remove only new /_preview proxy exposure, validate config and reload under authorized procedure. Restore previous pinned app image/config only if selectively approved; preserve original network, listener, certificates, DB and all volumes. Keep additive schema and audit/history intact. Migration down() refuses populated namespace; NEVER use migrate:rollback, DROP ... CASCADE or volume deletion on populated staging. If restoring data is necessary, restore into a new isolated Sites-owned database, compare evidence and obtain explicit cutover approval; do not overwrite the existing database. Verify old root/up and all other products' IDs/start counts/health unchanged. Record rollback operator and times. These steps are a proposal, not proof of rollback execution.

## Safe access after approved installation

Private VPN/SSH tunnel already authorized to VPS loopback18090, hostname matching the existing certificate and CA trust, nominal credential delivered by secure channel. Open `https://<approved-certificate-hostname>:<forwarded-port>/_preview`. This placeholder is not an installed URL. Opening localhost on the user's computer without a tunnel does not reach the VPS. No credentials, console session URL or key should enter GitHub/chat.

## Executable candidate: plan / apply / check

`provision.php` now implements the database portion of the candidate. It is a manually invoked CLI, never a route, boot hook, deployment workflow or migration auto-runner. It uses `PrivatePreviewProvisioning`, the immutable base migration `2026_11_16_000004_add_private_preview_workspace` and additive `2026_11_18_000008_add_private_preview_review_notes`. The contract SHA binds the versioned bundle of both SQL files; the example manifest carries this bundle fingerprint. It does not start containers, change ports, install images, configure proxy authentication, create SQL LOGINs/passwords, issue a Portal identity, or grant commercial authority.

The operational destination must be the existing **sites_staging** PostgreSQL18 database. Other database names are refused; `sites_preview_http_ci` is available only to the explicit GitHub Actions disposable fixture. The namespace `sites_preview` and dedicated owner must either both be absent, or both match a previous successful application exactly. A namespace created manually or by the older superuser-owned fixture is refused. An older managed V1 installation still returns `PROVISION_DENIED:managed_upgrade_required` from the fresh-install commands; its marker is never reinterpreted as the new V2 bundle. The separate `upgrade-plan` / `upgrade-apply` / `upgrade-check` procedure below is the candidate for that transition. There is no automatic ownership transfer, schema replacement, destructive rollback, permission repair, revocation reversal or validity renewal.

### Prerequisites and custody

Keep all existing release gates above. Before an eventual authorized invocation, the operator supplies these reviewed prerequisites through the existing custody mechanism:

- A one-shot bootstrap connection with the existing administrator identity; PostgreSQL18, `APP_ENV=staging`, debug disabled, `pgsql` default connection, no database URL override, empty table prefix and `public` search path.
- `sslmode=verify-full` and the approved mounted CA. Both effective Laravel configuration and the actual PostgreSQL session are checked against the reviewed database name/OID, login, server address/port and cluster `system_identifier`; the actual session must use TLS.
- The administrator password mounted at `/run/secrets/migration_owner`, mode0400/0600, with `SITES_DB_PASSWORD_FILE` pointing to that exact path. The effective connection password must match the mounted value. Do not mount this file in the long-running web container.
- An existing Laravel `public.migrations` repository. The provisioner never creates or replaces this repository in an operational database.
- Distinct, pre-existing dedicated SQL LOGINs for each named access, with no privileged attributes, no role memberships, no owned objects and no schema/database CREATE. Passwords and nominal Nginx identity are prepared separately by the custodian; this tool neither accepts nor creates them.
- A reviewed manifest mounted at `/run/secrets/preview_provisioning`, mode0400/0600, owned by root or the invoking UID. The CLI rejects other paths, links, oversized files and unknown fields. `provision-manifest.example.json` is deliberately ineligible: its placeholders and zero values must not be treated as a valid target or approved access.

The manifest contains the contract SHA-256, exact target metadata, and each pre-existing login's approved demonstration company UUID, profile (`author`, `reviewer`, `reader`) and integer expiration time. It contains no password. Its company/login details stay in private custody; only sanitized counts, action names and fingerprints are printed.

### Read-only plan

In the reviewed candidate image, after the above custody and connection prerequisites are in place:

```sh
php /opt/imagine/ops/preview-candidate/provision.php plan --manifest=/run/secrets/preview_provisioning
```

`ready` describes a new, additive installation. `already_applied` means the manifest and all managed state still agree; the actions array is empty. A nonzero exit with a bounded `PROVISION_DENIED` reason means stop and review the mismatch. Plan fingerprints bind the source contract, exact observed database identity, existing role attributes, migration/ownership state and approved manifest. Plan does not write a file, role, schema, binding or migration record.

### Explicit application, only after operational approval

The operator reviews the concrete plan and uses its exact `plan_sha256`:

```sh
php /opt/imagine/ops/preview-candidate/provision.php apply --manifest=/run/secrets/preview_provisioning --plan-sha=<reviewed-sha256> --authorize-apply=SITES_PREVIEW_ONLY_APPROVED
```

The flag is a guard against accidental CLI use; it does not replace the required human operational authorization, backup/recovery evidence or independent review. This repository does not invoke this command on a VPS.

Application rechecks the plan under a transaction-scoped lock. It creates only the dedicated `sites_preview_owner` NOLOGIN/NOINHERIT nonprivileged owner, temporarily grants it database CREATE, invokes migrations000004/000008 under `SET LOCAL ROLE`, resets to the verified bootstrap identity and removes temporary CREATE. The migration ledger, profile grants, company bindings and ownership marker are committed in the **same transaction** after full verification. Any failure rolls back the owner, schema, grants, bindings and both ledger entries together. Both migration names use the same batch; each is verified explicitly. It never changes `sites_core` or other product namespaces.

Authors receive read plus INSERT on projects/versions. Reviewers receive read plus EXECUTE on both signatures of the independent review function. The legacy five-argument signature delegates to the note-aware API with NULL: approvals remain compatible, while new requests for changes require guidance and are refused without it. Readers receive read only. No profile gets direct writes to reviews, audit or principals, sequence access, mutation/DDL rights, grant options or owner membership. The SQL login remains the original authenticated session identity for RLS and attribution.

A second application with a fresh matching plan returns `already_applied` and `changed:false`. It performs no migration/grant/binding writes and does not extend validity. Revoked/expired bindings, unexpected permissions, altered function definitions/search paths, owner privileges/membership or inconsistent migration records block both checking and reapplication. The operator must investigate them through a separately reviewed change.

### Read-only verification

```sh
php /opt/imagine/ops/preview-candidate/provision.php check --manifest=/run/secrets/preview_provisioning
```

Check verifies owner attributes and membership, the six relation objects and six exact function signatures, definitions/constraints/indexes/triggers/policies against the managed structural fingerprint, FORCE RLS, effective and direct ACLs including column grants/PUBLIC/grant options, exact bindings and migration ledger consistency. It is independent of the volume of demonstration projects and preserves all history. It returns `verified` with `can_accept_orders:false` and `can_publish:false`; it cannot prove backup offsite, human acceptance, deployment readiness or payment authority.

### Editorial guidance and existing history

The additive notes migration preserves every original review and audit row. New requests for changes require a nonblank note of at most2000 Unicode codepoints; approvals may omit it. Text retains its original spacing and line breaks and is rendered as escaped text. Notes belong to the exact immutable version/digest; a new version starts without the previous decision. The current version's guidance remains visible while browsing older history pages.

The migration preserves the original five-argument function identity and grants and copies only its existing EXECUTE permissions to the six-argument API. It creates no principal or additional table-write capability. Its rollback locks the review table, requires proof of complete row visibility and refuses any non-NULL note, so new guidance cannot be silently discarded.

### Verification of the implementation

`tests/private-preview-provisioning-offline.php` validates 26 manifest cases without Laravel boot or SQL. The HTTP fixture uses the real provisioner against its verified disposable TLS database. It exercises a read-only plan, authorization/hash/target refusals, state drift after planning, injected failure after migration+ledger to prove full transaction rollback, successful apply/check, repeat no-op, and refusal of extra ACLs, owner LOGIN, revocation and function search-path drift. The existing browser journey then runs with the segregated NOLOGIN owner and minimal profiles, including the same roles used by the candidate. Synthetic LOGIN creation is confined to the fixture and is never part of the provisioner.

These are candidate/CI checks, not evidence of any invocation on the Leaseweb VPS. Existing staging containers, loopback18090, data, certificates and services remain outside this change.

## Separate candidate: upgrade an existing managed V1 namespace

This procedure closes the compatibility gap between the original managed preview contract and the additive editorial-notes contract. **No managed V1 installation has been confirmed on the VPS.** This repository provides a candidate procedure and isolated verification; it does not establish that an operational upgrade is needed, authorized or executed. The inventory, independent review, backup/recovery evidence and operational approvals above remain prerequisites.

The upgrade accepts only a verified `SITES_PREVIEW_MANAGED_V1` source created by the original provisioner, with its existing dedicated NOLOGIN owner, exact structure, permissions, live principal bindings and migration000004 ledger record. A partially configured, manually created, drifted, revoked or expired source is refused. An absent namespace or a fresh V2 installation has no V1 upgrade lineage and returns `PROVISION_DENIED:upgrade_source_required`.

### Distinct manifest and command guards

Mount the privately reviewed upgrade manifest at **`/run/secrets/preview_upgrade`**, mode0400/0600 and owned by root or the invoking UID. The same file-size, symlink, ownership, TLS, one-shot bootstrap and exact database-identity rules as the fresh procedure apply. The CLI does not accept the fresh-install manifest path for an upgrade, or the upgrade path for a fresh installation.

The exact top-level schema is `SITES_PREVIEW_UPGRADE_V1`:

| Field | Required meaning |
| --- | --- |
| `schema` | `SITES_PREVIEW_UPGRADE_V1` |
| `source_contract_sha256` | Exact original001 fingerprint: `5ecfa4e626c622b387047d51e3a672b733d5830283b4e34d847f7469df5279eb` |
| `target_contract_sha256` | Exact001+002 bundle fingerprint: `d0c26c3bcc71c0615ad8efeeb6ebc1b10b9bede37a82d90173a913863ffc08d5` |
| `target` | Reviewed database, bootstrap login, host/port, observed server address, database OID and cluster system identifier |
| `principals` | The same existing login, company UUID, profile and expiration values as the verified source bindings |

`upgrade-manifest.example.json` contains placeholders and zero values and is deliberately ineligible. It contains no credentials and must not be used as proof of a destination or access approval. Do not substitute newer expirations, another company or additional principals when preparing the upgrade manifest: this procedure does not renew or expand access.

`upgrade-apply` requires its own `--authorize-upgrade=SITES_PREVIEW_UPGRADE_ONLY_APPROVED` guard and the exact reviewed `--plan-sha`. The fresh `--authorize-apply` flag is refused even when the correct upgrade flag is also supplied. Fresh commands similarly refuse `--authorize-upgrade`. All plan/check commands refuse write flags and plan hashes. These guards prevent accidental command reuse; they do not supply the required human operational authorization.

### Review a concrete source plan

After the existing custody and inventory prerequisites have been satisfied, the read-only candidate command is:

```sh
php /opt/imagine/ops/preview-candidate/provision.php upgrade-plan --manifest=/run/secrets/preview_upgrade
```

The `SITES_PREVIEW_UPGRADE_PLAN_V1` result has status `ready` only for the verified V1 source. Its `plan_sha256` binds the requested transition to the observed identity, managed source and existing permissions/bindings. Review the action list and fingerprints privately before approving application. A change in the bound state invalidates the reviewed plan; a failed check must be investigated, not repaired by this command.

The source inventory has five exact function signatures; the target has six because it adds the note-aware review API. Both retain the same five tables plus the audit sequence. Existing project/version/review/audit contents are preserved. The existing migration000004 entry keeps its original `id` and `batch`; the procedure does not replay000004 or rewrite that ledger entry.

### Apply only the additive transition after approval

```sh
php /opt/imagine/ops/preview-candidate/provision.php upgrade-apply --manifest=/run/secrets/preview_upgrade --plan-sha=<reviewed-upgrade-sha256> --authorize-upgrade=SITES_PREVIEW_UPGRADE_ONLY_APPROVED
```

Application rechecks the plan under the provisioner transaction lock, applies only migration000008 under the existing owner and verifies the resulting state before commit. Migration000008 takes its own exclusive review-table lock. The new ledger record uses a later batch. Schema migration, that ledger record and the new managed marker are committed together; any failure rolls back the transition together.

The upgrade does not create roles, transfer ownership, reinstall profile grants, rewrite company bindings or extend expirations. Migration000008 preserves the original five-argument review function's OID, owner and ACL, and copies its existing EXECUTE permissions to the new six-argument function. This is the only additive review capability; it does not give any profile direct review/audit writes, grant options or owner membership.

The V2 marker records an `upgrade` lineage object with schema `SITES_PREVIEW_UPGRADE_LINEAGE_V1`, the source contract/structure fingerprints, and both `base_migration` and `notes_migration` identities (`id` and `batch`). This records the particular additive transition instead of pretending both migrations ran in the same installation batch.

The result schema is `SITES_PREVIEW_UPGRADE_RESULT_V1`. A completed transition returns `status:upgraded` and `changed:true`. Repeating application is supported only with a newly reviewed matching plan from the resulting state: it returns `status:already_upgraded` and `changed:false`, without migration, grant or binding writes. Reusing the pre-upgrade plan after the state changed is refused as `PROVISION_DENIED:stale_plan`.

### Verify lineage and coordinate application compatibility

```sh
php /opt/imagine/ops/preview-candidate/provision.php upgrade-check --manifest=/run/secrets/preview_upgrade
```

The read-only `SITES_PREVIEW_UPGRADE_CHECK_V1` result returns `status:verified` only after it verifies the upgraded structure, owner, permissions, bindings, ledger and recorded V1 lineage. `upgrade-check` on an untouched V1 source returns `PROVISION_DENIED:upgrade_not_applied`. A plan for an already verified upgrade returns `status:already_upgraded` and no actions. Every result retains `can_accept_orders:false` and `can_publish:false`.

The application/schema compatibility change must be included in the approved release plan. Legacy approval calls through the five-argument function remain supported. **A legacy client that calls `changes_requested` without a note stops being able to submit that decision after the upgrade.** It must use the note-aware API and provide nonblank guidance. Existing historical change requests without notes are preserved; the tool does not fabricate or backfill text. The note-aware UI expects the additive schema, so deploying that UI is a separate coordinated release action.

Do not use destructive rollback to regain old client behavior. Disable the affected preview flow through the approved procedure and preserve its history while compatibility is reviewed. Migration000008 refuses rollback when any non-NULL note exists. The original stack, images, listener18090, networks, volumes and certificates are not changed by this database candidate.

### Offline CLI checks and isolated SQL verification

`php tests/private-preview-upgrade-cli-offline.php` exercises44 help/argument cases before secret custody checks or Laravel boot. Child processes restrict filesystem reads to this repository. The checks cover separate manifest paths and write guards, mixed/duplicate/unknown options, missing authorization/hash and write options on read-only commands. Every refusal has an exact bounded `PROVISION_CLI_DENIED` reason and an empty stdout; no manifest, secret or database is opened.

The real V1-to-V2 transition, preserved history/ledger/ACLs, stale plans, injected rollback and repeated no-op require the isolated PostgreSQL/TLS fixture. Offline argument checks do not substitute for that evidence. No command in this section has been invoked on operational infrastructure as part of this development work.
