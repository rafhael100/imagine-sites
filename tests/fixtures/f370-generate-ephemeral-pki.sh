#!/usr/bin/env bash
# F1-370: fresh, ephemeral PKI for CI-only mTLS. NEVER commit generated keys.
set -euo pipefail
[ "\${GITHUB_ACTIONS:-}" = true ] || { echo 'CI only' >&2; exit 1; }
[ "\${APP_ENV:-}" = testing ] || { echo 'testing only' >&2; exit 1; }
[ "\${SITES_F370_MTLS_LAB:-}" = 1 ] || { echo 'lab opt-in required' >&2; exit 1; }
out="\${1:-}"
[ -n "$out" ] || exit 1
[ ! -e "$out" ] || { echo 'Refusing existing certificate path' >&2; exit 1; }
umask 077
mkdir -m 700 "$out"

openssl req -x509 -new -sha256 -nodes -newkey rsa:2048 \
  -subj '/CN=Imagine-F370-Ephemeral-Test-CA' \
  -keyout "$out/ca.key" -out "$out/ca.crt" -days 1 \
  -addext 'basicConstraints=critical,CA:TRUE' \
  -addext 'keyUsage=critical,keyCertSign,cRLSign' >/dev/null 2>&1

openssl req -new -sha256 -nodes -newkey rsa:2048 \
  -subj '/CN=sites-f370.test' \
  -keyout "$out/server.key" -out "$out/server.csr" >/dev/null 2>&1
cat > "$out/server.ext" <<'EOF'
basicConstraints=critical,CA:FALSE
keyUsage=critical,digitalSignature,keyEncipherment
extendedKeyUsage=serverAuth
subjectAltName=DNS:sites-f370.test
EOF
openssl x509 -req -in "$out/server.csr" \
  -CA "$out/ca.crt" -CAkey "$out/ca.key" -CAcreateserial \
  -days 1 -sha256 -out "$out/server.crt" \
  -extfile "$out/server.ext" >/dev/null 2>&1

openssl req -new -sha256 -nodes -newkey rsa:2048 \
  -subj '/CN=imagine-portal-f370-client' \
  -keyout "$out/client.key" -out "$out/client.csr" >/dev/null 2>&1
cat > "$out/client.ext" <<'EOF'
basicConstraints=critical,CA:FALSE
keyUsage=critical,digitalSignature
extendedKeyUsage=clientAuth
EOF
openssl x509 -req -in "$out/client.csr" \
  -CA "$out/ca.crt" -CAkey "$out/ca.key" -CAcreateserial \
  -days 1 -sha256 -out "$out/client.crt" \
  -extfile "$out/client.ext" >/dev/null 2>&1

# Untrusted client cert: not signed by the gateway's ephemeral CA.
openssl req -x509 -new -sha256 -nodes -newkey rsa:2048 \
  -subj '/CN=impostor-f370' \
  -keyout "$out/untrusted.key" -out "$out/untrusted.crt" \
  -days 1 \
  -addext 'extendedKeyUsage=clientAuth' >/dev/null 2>&1
openssl verify -CAfile "$out/ca.crt" "$out/server.crt" "$out/client.crt" >/dev/null
if openssl verify -CAfile "$out/ca.crt" "$out/untrusted.crt" >/dev/null 2>&1; then
  echo 'Unsafe: impostor certificate trusted by gateway' >&2
  exit 1
fi
chmod 600 "$out"/*.key
echo 'F1-370 ephemeral CA, server, legitimate client and untrusted client generated locally.'
