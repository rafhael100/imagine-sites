# Onepage current-head mTLS transport — candidate 2026-11-19

Independent candidate based on [Sites #550](https://github.com/rafhael100/imagine-sites/pull/550) exact commit `151c350ff4a3468767dd6cb6a0d592ceb6ebf68f`, on the #541/#542 Portal–Sites lineage. No changes to previous branches or to Portal.

## What was implemented

`PortalOnepageHeadMtlsHttpSource` is an **unbound** candidate adapter of `CurrentPortalOnepageHeadSource` for a future operator-configured `POST /internal/sites/v1/brief-head/current`. It requires HTTPS, port 443, exact DNS host, an exact private route, mTLS certificate/key and trusted CA files, TLS verification, disabled redirects and proxies, timeouts, response size limit and JSON media type. It never accepts a client-supplied destination or runtime credentials in the repo.

The eleven-field head is strictly parsed with `BriefCandidateJson`: duplicate keys, authority flags, mismatched tenant/order/site, invalid UUIDs, digest tampering, coercible revision numbers and Landing Page substitution fail closed. Errors return null without leaking network, tenant or identity details. Independent, native PostgreSQL tenancy and snapshot readback remain a separate requirement in `StoredOnepageReviewCurrentHeadGate`.

This parser only validates **data shape**. The source interface remains without an operational provider. mTLS alone cannot prove Portal approval, reviewer identity, revocation, consent, payment or authorization to publish. The proposed endpoint does not yet exist in Portal; no network request is exercised in the offline test and this is not a functioning integration.

## Test and next requirements

Draft-only workflow exercises the offline adversarial contract in PHP 8.3/8.4 plus inherited disposable PostgreSQL 18 snapshot/registry/current-head tests. All assertions remain laboratory evidence, not operational sign-off.

Next: Portal must implement an immutable, approved, versioned and revocable current head with a transactionally consistent, authenticated reader; create the private S2S endpoint with explicit service permission, replay defense and real certificate-exchange homologation; only then bind this adapter. Persist independent internal review decisions and audit, separate Landing Page, and independently homologate technical publication and client acceptance.

**DRAFT / NO-MERGE / NO-DEPLOY / NO-GO.** Ten operational gates blocked. No VPS, credentials, Cloudflare, DNS, Asaas, publication, payment or supplier changes.
