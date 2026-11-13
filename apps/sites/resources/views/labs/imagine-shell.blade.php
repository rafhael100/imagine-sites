{{-- Imagine DS v1: local aliases of Portal Node original, administrative shell only.
     Source of truth: imagine-portal PR #589 design-system/tokens.css.
     Do NOT include this partial in customer site / landing-page HTML. --}}
:root {
  --imagine-orange: #f9941d;
  --imagine-ink: #171b19;
  --imagine-white: #ffffff;
  --imagine-surface: #f7f7f8;
  --imagine-muted: #737373;
  --imagine-border: #e8e8e8;
  --imagine-orange-soft: #fff1e0;
  --imagine-orange-text: #9b4d00;
  --imagine-focus: #b75c00;
  --imagine-radius-control: 6px;
  --imagine-radius-card: 9px;
  --imagine-sidebar-width: 240px;
  --imagine-font-ui: Arial, Helvetica, sans-serif;
  --sites-shell-bg: var(--imagine-surface);
  --sites-shell-ink: var(--imagine-ink);
  --sites-shell-accent: var(--imagine-orange);
  --sites-shell-accent-text: var(--imagine-orange-text);
  --sites-shell-muted-readable: #686868; /* Accessible on #F7F7F8 and white */
  font-family: var(--imagine-font-ui);
  color: var(--sites-shell-ink);
  background: var(--sites-shell-bg);
}
body { background: var(--sites-shell-bg); color: var(--sites-shell-ink); font-family: var(--imagine-font-ui); }
a:focus-visible, button:focus-visible, summary:focus-visible, iframe:focus-visible {
  outline: 3px solid var(--imagine-focus);
  outline-offset: 4px;
}
header {
  background: var(--imagine-white);
  color: var(--sites-shell-ink);
  border-bottom: 1px solid var(--imagine-border);
  box-shadow: 0 1px 3px rgba(23,27,25,.035);
}
header .brand { display:flex;gap:14px;align-items:center;letter-spacing:normal;font-weight:800 }
header .brand .imagine-original-logo { width:140px;height:auto;max-width:42vw;object-fit:contain; }
header .brand .brand-app { display:block;border-left:1px solid var(--imagine-border);padding-left:14px; }
header .brand small { color: var(--imagine-muted);letter-spacing:.04em; }
.flag { background:var(--imagine-surface);border-color:var(--imagine-border);color:var(--imagine-ink);font-weight:700; }
.kicker {color:var(--sites-shell-accent-text)}
.sub, dt, .steps small, .foot, .chrome span, .locked, .editorial-summary .sub {color:var(--sites-shell-muted-readable)}
.card,.preview {
  background:var(--imagine-white);
  color:var(--imagine-ink);
  border:1px solid var(--imagine-border);
  border-radius:var(--imagine-radius-card);
  box-shadow:0 4px 18px rgba(23,27,25,.035);
}
.card { padding:21px; }
.tabs a {border-color:var(--imagine-border);border-radius:var(--imagine-radius-control);}
.tabs a:hover {background:var(--imagine-surface);border-color:#cecece;}
.tabs a[aria-current] {
  background:var(--imagine-orange-soft);
  border-color:var(--imagine-orange);
  color:var(--imagine-ink);
  box-shadow:inset 3px 0 0 var(--imagine-orange);
}
.workspace {background:var(--imagine-surface)}
.browser {border-color:var(--imagine-border);box-shadow:0 14px 35px rgba(23,27,25,.09)}
.chrome {background:var(--imagine-surface);border-bottom-color:var(--imagine-border)}
.chrome i {background:#c4c4c4}
.preview-head,.foot,.qa-summary,.editorial-summary {border-color:var(--imagine-border)}
.skip-preview, code,.social-card-preview .social-card-kicker {color:var(--imagine-orange-text)}
.note,.locked,.editorial-note {
  background:var(--imagine-surface);border-color:var(--imagine-border);color:var(--imagine-ink);
}
.layout-evidence-detail pre {border-color:var(--imagine-border);background:var(--imagine-surface)}
.social-card-preview {background:var(--imagine-surface);border-color:var(--imagine-border)}
.social-card-preview h3 {color:var(--imagine-ink)}
.social-card-preview p {color:var(--sites-shell-muted-readable)}
/* Status green/red/yellow remain semantic signals, never transformed into brand orange. */
@media(max-width:610px) {
  header .brand {flex-wrap:wrap;gap:8px}
  header .brand .brand-app {padding-left:10px}
  header .brand .imagine-original-logo {width:128px}
}
@media (prefers-reduced-motion: reduce) {
  *,*::before,*::after {scroll-behavior:auto!important;transition-duration:.01ms!important;animation-duration:.01ms!important;}
}
