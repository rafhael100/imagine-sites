# Sites → Portal Landing Page: site-bound readback (20/11/2026, draft)

Follow-up to Sites #541/#542, #553/#554 and Portal #1008/#1010. The Portal
admission prior to #1010 checked a contract by order alone, not independent
site ownership. The Portal candidate now requires a second source bound to
tenant/order/site, contract and revision; Sites pins #1010 and replays adversarial
cross-site/revocation tests through the actual Portal and Sites classes.

The Sites `PortalLandingPageAdmissionReadbackCandidate` accepts an injected
independent Portal source and checks **two** canonical admissions against
separately established tenant/order/site/contract/revision expectations. It
refuses unavailable/foreign/changed/uncanonical responses, calls every source
anew on retry, and has a deny-only default provider. On success it returns
six **selectors**; they are not customer approval, briefing, release or grants.

**Known NO-GO**: The CI witness, site binding and contract are synthetic. There
is no official tenant-aware PostgreSQL approved/versioned Landing Page briefing,
atomic approval/revocation/audit source, authenticated S2S transport, production
reader registration, renderer/editorial approval, published release or customer
acceptance. Double reads are NOT a transaction. An incorrectly implemented
source could still return stale data. All ten gates remain blocked.

Draft PR only. No merge, main edit, deploy, real payment, Cloudflare/DNS, VPS,
credentials, Asaas, production data or new suppliers. Existing visual identity
is unchanged.
