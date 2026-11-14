#!/usr/bin/env bash
# F1-388. Disposable GitHub Actions ONLY. DO NOT run on Leaseweb.
set -euo pipefail
test "${GITHUB_ACTIONS:-}" = true
test "${SITES_F388_MTLS_LAB:-}" = 1
test "${F388_FIXTURE_DIR:-}" = /tmp/f388-fixture
umask 077
install -d -m 0700 "$F388_FIXTURE_DIR"
cd "$F388_FIXTURE_DIR"
openssl req -new -x509 -sha256 -newkey rsa:2048 -nodes -days 1 \
  -keyout ca.key -out ca.crt -subj '/CN=F388 Ephemeral CI CA' \
  -addext 'basicConstraints=critical,CA:TRUE' >/dev/null 2>&1
openssl req -new -sha256 -newkey rsa:2048 -nodes \
  -keyout server.key -out server.csr -subj '/CN=localhost' >/dev/null 2>&1
printf 'subjectAltName=DNS:localhost\nextendedKeyUsage=serverAuth\n' > server.ext
openssl x509 -req -sha256 -days 1 -in server.csr -CA ca.crt \
  -CAkey ca.key -CAcreateserial -extfile server.ext \
  -out server.crt >/dev/null 2>&1
openssl req -new -sha256 -newkey rsa:2048 -nodes \
  -keyout client.key -out client.csr -subj '/CN=imagine-sites-ci' >/dev/null 2>&1
printf 'extendedKeyUsage=clientAuth\n' > client.ext
openssl x509 -req -sha256 -days 1 -in client.csr -CA ca.crt \
  -CAkey ca.key -CAcreateserial -extfile client.ext \
  -out client.crt >/dev/null 2>&1
openssl req -new -x509 -sha256 -newkey rsa:2048 -nodes -days 1 \
  -keyout wrong-ca.key -out wrong-ca.crt -subj '/CN=Untrusted F388 CA' \
  -addext 'basicConstraints=critical,CA:TRUE' >/dev/null 2>&1
openssl req -new -sha256 -newkey rsa:2048 -nodes \
  -keyout wrong-client.key -out wrong-client.csr \
  -subj '/CN=untrusted-client' >/dev/null 2>&1
openssl x509 -req -sha256 -days 1 -in wrong-client.csr \
  -CA wrong-ca.crt -CAkey wrong-ca.key -CAcreateserial \
  -extfile client.ext -out wrong-client.crt >/dev/null 2>&1
chmod 0600 ./*.key
chmod 0644 ./*.crt
openssl verify -CAfile ca.crt server.crt client.crt
echo 'F1-388 PASS: ephemeral CA, correct client/server and untrusted client prepared.'
