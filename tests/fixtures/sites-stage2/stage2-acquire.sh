#!/usr/bin/env bash
# Acquisition only: this script must never start postgresql or execute application code.
set -euo pipefail
umask 077
export LC_ALL=C DEBIAN_FRONTEND=noninteractive
[[ ${GITHUB_ACTIONS:-} == true && ${GITHUB_EVENT_NAME:-} == pull_request &&
   ${GITHUB_RUN_ATTEMPT:-} == 1 && ${GITHUB_REPOSITORY:-} == rafhael100/imagine-sites &&
   $(grep -cx 'ID=ubuntu' /etc/os-release) == 1 &&
   $(grep -cx 'VERSION_ID="24.04"' /etc/os-release) == 1 &&
   $(dpkg --print-architecture) == amd64 ]] || { echo STAGE2_ACQUISITION_SCOPE_REFUSED >&2; exit 78; }
unset COMPOSER_AUTH GIT_CONFIG_COUNT GH_TOKEN GITHUB_TOKEN SSH_AUTH_SOCK PGPASSWORD PGHOST PGHOSTADDR PGSERVICE
export GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null GIT_TERMINAL_PROMPT=0
A=$(mktemp -d "${RUNNER_TEMP:?}/sites-stage2-acquire.XXXXXXXX")
P="$A/portal"; R="$A/runtime"; T="$R/root"; mkdir -p "$P" "$T" "$A/debs" "$A/apt/partial" "$A/apt-ubuntu/partial"
# APT sandbox user may traverse only this directory of public package metadata.
chmod 0711 "$A"; chmod 0755 "$A/apt" "$A/apt-ubuntu"
KEY="$A/pgdg.gpg"
curl -q --noproxy '*' --proto '=https' --tlsv1.2 -fsS --max-time 20 --max-filesize 65536 \
  https://www.postgresql.org/media/keys/ACCC4CF8.asc -o "$A/pgdg.asc"
mkdir -m 0700 "$A/gnupg"
fpr=$(gpg --batch --no-options --homedir "$A/gnupg" --with-colons --show-keys --fingerprint "$A/pgdg.asc" | awk -F: '
 $1=="pub"{n++; p=1} $1=="fpr"&&p{print $10;p=0} END{if(n!=1)exit 1}')
test "$fpr" = B97B0AFCAA1A47F044F244A07FCC7D46ACCC4CF8
gpg --batch --no-options --homedir "$A/gnupg" --dearmor --output "$KEY" "$A/pgdg.asc"
chmod 0644 "$KEY"
K=/usr/share/keyrings/ubuntu-archive-keyring.gpg
test -r "$K"
cat >"$A/sources.list" <<EOF
deb [signed-by=$K] https://archive.ubuntu.com/ubuntu noble main universe
deb [signed-by=$K] https://archive.ubuntu.com/ubuntu noble-updates main universe
deb [signed-by=$K] https://security.ubuntu.com/ubuntu noble-security main universe
deb [signed-by=$KEY] https://apt.postgresql.org/pub/repos/apt/ noble-pgdg main
EOF
head -n 3 "$A/sources.list" >"$A/ubuntu.list"
chmod 0644 "$A/sources.list" "$A/ubuntu.list"
aptopt=(-o "Dir::Etc::sourcelist=$A/sources.list" -o Dir::Etc::sourceparts=- -o "Dir::State::lists=$A/apt" -o Acquire::Check-Valid-Until=true -o Acquire::AllowInsecureRepositories=false -o APT::Get::AllowUnauthenticated=false)
ubuntuopt=("${aptopt[@]}" -o "Dir::Etc::sourcelist=$A/ubuntu.list" -o "Dir::State::lists=$A/apt-ubuntu")
sudo -n apt-get "${aptopt[@]}" -o APT::Update::Error-Mode=any update >/dev/null
sudo -n apt-get "${ubuntuopt[@]}" -o APT::Update::Error-Mode=any update >/dev/null
packages=(php8.3-cli php8.3-pgsql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip composer
  libicu74 libldap2 liblz4-1 libzstd1 libxml2 libssl3t64 libkrb5-3 libreadline8t64 libllvm17t64)
if pgrep -x postgres >/dev/null; then echo STAGE2_EXISTING_POSTGRES_REFUSED >&2; exit 1; fi
plan=$(sudo -n apt-get "${ubuntuopt[@]}" -s --no-upgrade --no-install-recommends install "${packages[@]}")
if grep -Eq '^(Remv |Inst postgresql)' <<<"$plan"; then echo STAGE2_PACKAGE_PLAN_REFUSED >&2; exit 1; fi
# Do not install the extracted PGDG server or start a database during acquisition.
sudo -n env NEEDRESTART_MODE=l apt-get "${ubuntuopt[@]}" -y --no-upgrade --no-install-recommends install "${packages[@]}" >/dev/null
if pgrep -x postgres >/dev/null; then echo STAGE2_EXISTING_POSTGRES_REFUSED >&2; exit 1; fi
(
  cd "$A/debs"
  V=18.7-1.pgdg24.04+2
  apt-get "${aptopt[@]}" download "postgresql-18=$V" "postgresql-client-18=$V" "libpq5=$V" >/dev/null
  shopt -s nullglob
  f=(*.deb); test "${#f[@]}" = 3
  found=()
  for d in "${f[@]}"; do
    test "$(dpkg-deb -f "$d" Version)" = "$V"
    test "$(dpkg-deb -f "$d" Architecture)" = amd64
    pkg=$(dpkg-deb -f "$d" Package)
    case "$pkg" in
      postgresql-18) h=902e12bad8a7527199712b9f502b5ce14054289a8e138d1df9e85e86cecdaf1e; size=7660020;;
      postgresql-client-18) h=2c102e1d6ab4ae0abf5bb1e55240feb2a2b7d81a2d0f78b29614611d88ed746e; size=2119684;;
      libpq5) h=c982d16d81c3dacba5b85797af37c5e6b34a73e20ddbbf41a61098276db53a3d; size=265004;;
      *) exit 1;;
    esac
    test "$(stat -c %s "$d")" = "$size"
    printf '%s  %s\n' "$h" "$d" | sha256sum --strict -c - >/dev/null
    found+=("$pkg")
  done
  test "$(printf '%s\n' "${found[@]}" | sort)" = $'libpq5\npostgresql-18\npostgresql-client-18'
  for d in "${f[@]}"; do dpkg-deb -x "$d" "$T"; done
)
test -f "$T/usr/share/postgresql/18/extension/pgcrypto.control"
test -x "$T/usr/lib/postgresql/18/bin/postgres"
test -f "$T/usr/lib/x86_64-linux-gnu/libpq.so.5"
portal=4dc198d2109fe1c7590b5f012e77757ed593e1d5
git -C "$P" init -q
git -C "$P" fetch -q --depth=1 --no-tags https://github.com/rafhael100/imagine-portal.git "$portal"
git -C "$P" checkout -q --detach FETCH_HEAD
test "$(git -C "$P" rev-parse HEAD)" = "$portal"
test -z "$(git -C "$P" status --porcelain=v1 --untracked-files=all)"
cmp -s "$P/apps/portal/composer.lock" <(git -C "$P" show "$portal:apps/portal/composer.lock")
export COMPOSER_HOME="$A/composer-home"
export COMPOSER_CACHE_DIR="$A/composer-cache"
# Force Git objects to verify by their pinned IDs; dist fallback must fail verification.
git config --global --list >/dev/null 2>&1 || test "$GIT_CONFIG_GLOBAL" = /dev/null
/usr/bin/php8.3 /usr/bin/composer install --working-dir="$P/apps/portal" --no-interaction \
  --no-progress --prefer-source --no-dev --no-scripts --no-plugins >/dev/null
python3 -I -B - "$P/apps/portal" <<'PY'
import json, pathlib, re, subprocess, sys
app=pathlib.Path(sys.argv[1]).resolve(strict=True)
v=app/'vendor'
def git(p,*args):
    return subprocess.check_output(['/usr/bin/git','-C',str(p),*args])
def check(value):
    if not value: raise SystemExit('STAGE2_COMPOSER_SOURCE_REFUSED')
lock=json.loads((app/'composer.lock').read_bytes())['packages']
install=json.loads((v/'composer/installed.json').read_bytes())['packages']
check(len(lock)>0 and {x['name'] for x in lock}=={x['name'] for x in install})
for x in lock:
    ref=x['source']['reference']; url=x['source']['url']
    check(x['source']['type']=='git' and re.fullmatch(r'[0-9a-f]{40}',ref)
          and re.fullmatch(r'https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+',url))
    path=v/x['name']
    check(path.is_dir() and path.resolve()==path and (path/'.git').is_dir())
    check(git(path,'rev-parse','HEAD').strip().decode()==ref)
    check(git(path,'diff','--exit-code',ref,'--')==b'' and git(path,'ls-files','--others','-z')==b'')
    check(not any(f.startswith((b'120000 ',b'160000 ')) for f in git(path,'ls-files','--stage').splitlines()))
print('STAGE2_LOCKED_SOURCES_OK',len(lock))
PY
cat /etc/php/8.3/cli/php.ini /etc/php/8.3/cli/conf.d/*.ini >"$R/php.ini"
(
  cd "$R"
  find root -type f -print0 | sort -z | xargs -0 sha256sum
  sha256sum php.ini
) >"$R/SHA256SUMS"
printf 'STAGE2_PORTAL_ROOT=%s\nSTAGE2_RUNTIME_DIR=%s\n' "$P" "$R" >>"${GITHUB_ENV:?}"
