/**
 * F1-264: deployment-disabled Cloudflare-Workers-compatible preview.
 *
 * The exported Worker handler ALWAYS refuses live requests, regardless of
 * bindings, headers, env flags or the caller's claims. The named helper can
 * serve only fixed .invalid local CI fixtures passed by trusted test code.
 *
 * No client data, R2/KV, DNS, remote fetch, forms, analytics or publication.
 */
const SYNTHETIC_HOST = 'preview.imagine.invalid';
const encoder = new TextEncoder();

const headers = Object.freeze({
  'Cache-Control': 'private, no-store, max-age=0',
  'X-Robots-Tag': 'noindex, nofollow, noarchive',
  'X-Content-Type-Options': 'nosniff',
  'Referrer-Policy': 'no-referrer',
  'X-Frame-Options': 'DENY',
  'Permissions-Policy': 'camera=(), microphone=(), geolocation=()',
  'Content-Security-Policy': "default-src 'none'; script-src 'none'; style-src 'unsafe-inline'; img-src 'none'; connect-src 'none'; frame-src 'none'; form-action 'none'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'",
});

function denied(status) {
  return new Response(status === 404 ? 'Not found' : 'Preview unavailable', {
    status, headers: {
      ...headers,
      'Content-Type': 'text/plain; charset=utf-8',
    },
  });
}

/**
 * A byte-exact render helper for isolated tests, NEVER an exposed Worker
 * route. A digest is not proof of editorial approval, tenant identity, or
 * permission to publish a customer site.
 *
 * @param {Request} request
 * @param {{synthetic:true, reviewState:string, publishable:false,
 *   clientAccepted:false, html:string, digest:string}|undefined} fixture
 * @returns {Promise<Response>}
 */
export async function renderSyntheticPreview(request, fixture) {
  if (!(request instanceof Request)) return denied(404);
  let target;
  try { target = new URL(request.url); } catch { return denied(404); }
  if (target.protocol !== 'https:' ||
      target.host !== SYNTHETIC_HOST ||
      target.username !== '' || target.password !== '' ||
      target.pathname !== '/' || target.search !== '' || target.hash !== '') {
    return denied(404);
  }
  if (request.method !== 'GET' && request.method !== 'HEAD') {
    return new Response('Method not allowed', {
      status: 405,
      headers: {...headers, Allow: 'GET, HEAD'},
    });
  }
  if (!fixture || Object.getPrototypeOf(fixture) !== Object.prototype ||
      fixture.synthetic !== true ||
      fixture.reviewState !== 'internal_review' ||
      fixture.publishable !== false ||
      fixture.clientAccepted !== false ||
      typeof fixture.html !== 'string' ||
      typeof fixture.digest !== 'string' ||
      !/^[0-9a-f]{64}$/.test(fixture.digest)) {
    return denied(503);
  }
  const bytes = encoder.encode(fixture.html);
  if (bytes.length < 150 || bytes.length > 150000 ||
      !fixture.html.startsWith('<!doctype html>\n<html lang="pt-BR">\n<head>') ||
      !fixture.html.includes('<meta name="robots" content="noindex, nofollow">') ||
      !fixture.html.includes('Prévia demonstrativa: formulários e envio de dados permanecem desabilitados.') ||
      /<\s*(script|form|iframe|object|embed|svg|link|base)\b/i.test(fixture.html) ||
      /(?:https?:\/\/|javascript:|data:)/i.test(fixture.html)) {
    return denied(503);
  }
  const hash = new Uint8Array(await crypto.subtle.digest('SHA-256', bytes));
  const digest = Array.from(hash, b => b.toString(16).padStart(2, '0')).join('');
  if (digest !== fixture.digest) return denied(503);

  return new Response(request.method === 'HEAD' ? null : bytes, {
    status: 200,
    headers: {
      ...headers,
      'Content-Type': 'text/html; charset=utf-8',
      'Content-Length': String(bytes.length),
    },
  });
}

/**
 * Live edge endpoint is intentionally hard-disabled until release governance,
 * real Portal authority, immutable acceptance and external observation exist.
 * No environment switch can enable publication in this module.
 */
export default Object.freeze({
  async fetch(_request, _env, _ctx) {
    return denied(503);
  },
});
