#!/usr/bin/env bash
# SQL fixture acquisition only; no PostgreSQL start or application/Composer code.
set -euo pipefail
umask 077
export LC_ALL=C DEBIAN_FRONTEND=noninteractive
[[ ${GITHUB_ACTIONS:-} == true && ${GITHUB_EVENT_NAME:-} == pull_request &&
   ${GITHUB_RUN_ATTEMPT:-} == 1 && ${GITHUB_REPOSITORY:-} == rafhael100/imagine-sites &&
   $(grep -cx 'ID=ubuntu' /etc/os-release) == 1 &&
   $(grep -cx 'VERSION_ID="24.04"' /etc/os-release) == 1 &&
   $(dpkg --print-architecture) == amd64 ]] || { echo BRIEF_V2_ACQUISITION_SCOPE_REFUSED >&2; exit 78; }
unset COMPOSER_AUTH GIT_CONFIG_COUNT GH_TOKEN GITHUB_TOKEN SSH_AUTH_SOCK PGPASSWORD PGHOST PGHOSTADDR PGSERVICE
export GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null GIT_TERMINAL_PROMPT=0
A=$(mktemp -d "${RUNNER_TEMP:?}/sites-brief-v2-acquire.XXXXXXXX")
ready=false
finish() {
  if ! $ready; then
    [[ ${A%/*} = "$RUNNER_TEMP" && ${A##*/} =~ ^sites-brief-v2-acquire\.[[:alnum:]]{8}$ &&
       ! -L $A && -O $A ]] && rm -rf -- "$A"
  fi
}
trap finish EXIT
R="$A/runtime"; T="$R/root"; mkdir -p "$T" "$A/debs" "$A/apt/partial" "$A/apt-ubuntu/partial"
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
packages=(php8.3-cli php8.3-pgsql
  libicu74 libldap2 liblz4-1 libzstd1 libxml2 libssl3t64 libkrb5-3 libreadline8t64 libllvm17t64 libnuma1)
if pgrep -x postgres >/dev/null; then echo STAGE2_EXISTING_POSTGRES_REFUSED >&2; exit 1; fi
plan=$(sudo -n apt-get "${ubuntuopt[@]}" -s --no-upgrade --no-install-recommends install "${packages[@]}")
if grep -Eq '^(Remv |Inst (postgresql|postgresql-client|openssh-server|docker|containerd))' <<<"$plan"; then echo STAGE2_PACKAGE_PLAN_REFUSED >&2; exit 1; fi
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
# Required by the pinned PG18 server. Extract one exact signed Noble package;
# do not install it on the runner or permit an alternate version.
(
  mkdir "$A/liburing-deb" || exit 1
  cd "$A/liburing-deb" || exit 1
  apt-get "${ubuntuopt[@]}" download 'liburing2=2.5-1build1' >/dev/null || exit 1
  shopt -s nullglob
  files=(*.deb)
  [[ "${#files[@]}" = 1 ]] || exit 1
  deb=${files[0]}
  [[ $(dpkg-deb -f "$deb" Package) = liburing2 &&
     $(dpkg-deb -f "$deb" Version) = 2.5-1build1 &&
     $(dpkg-deb -f "$deb" Architecture) = amd64 &&
     $(stat -c %s "$deb") = 21092 ]] || exit 1
  printf '%s  %s\n' c2aef62accee92a06263c3ad4ef46132c13e409b54296c44792a20491830a7b0 "$deb" |
    sha256sum --strict -c - >/dev/null || exit 1
  dpkg-deb -x "$deb" "$T" || exit 1
) || { printf 'STAGE2_PG_CLOSURE_REFUSED LIBURING_PACKAGE\n' >&2; exit 1; }
# --list resolves ELF dependencies only, with the runtime library path and no
# inherited environment. No PostgreSQL code or extension entry point runs.
(
  shopt -s nullglob
  lib=$T/usr/lib/x86_64-linux-gnu
  pg18=$T/usr/lib/postgresql/18
  loader=/lib64/ld-linux-x86-64.so.2
  u=$lib/liburing.so.2.5
  [[ -x "$loader" && -L "$lib/liburing.so.2" && $(readlink -- "$lib/liburing.so.2") = liburing.so.2.5 &&
     -f "$u" && ! -L "$u" && $(stat -c %s "$u") = 26856 ]] || exit 1
  printf '%s  %s\n' 51594b92ac35f96595d3fcec9c3ec5d190637d9b426d5f8a8f4207875d2962f6 "$u" |
    sha256sum --strict -c - >/dev/null || exit 1
  bins=("$pg18"/bin/*)
  [[ "${#bins[@]}" -ge 5 && "${#bins[@]}" -le 80 &&
     -f "$pg18/lib/pgcrypto.so" && ! -L "$pg18/lib/pgcrypto.so" ]] || exit 1
  for target in "${bins[@]}" "$pg18/lib/pgcrypto.so"; do
    tool=${target##*/}
    [[ "$tool" =~ ^[a-z_0-9.]{1,32}$ && -f "$target" &&
       $(realpath -e -- "$target") = "$pg18/"* ]] || exit 1
    if ! report=$(timeout 5s /usr/bin/env -i LC_ALL=C "$loader" --library-path "$lib" --list "$target" 2>&1); then
      printf 'STAGE2_PG_CLOSURE_REFUSED LOADER %s\n' "$tool" >&2
      exit 1
    fi
    if grep -Eq 'not found|cannot open shared object file|wrong ELF' <<<"$report"; then
      printf 'STAGE2_PG_CLOSURE_REFUSED UNRESOLVED %s\n' "$tool" >&2
      exit 1
    fi
    if [[ "$tool" = postgres ]]; then
      awk -v want="$lib/liburing.so.2" '$1=="liburing.so.2" && $2=="=>" && $3==want {n++} END {exit n!=1}' <<<"$report" || exit 1
    elif [[ "$tool" = psql ]]; then
      awk -v want="$lib/libpq.so.5" '$1=="libpq.so.5" && $2=="=>" && $3==want {n++} END {exit n!=1}' <<<"$report" || exit 1
    fi
  done
  printf 'STAGE2_PG_ELF_CLOSURE_OK %s_PLUS_PGCRYPTO\n' "${#bins[@]}"
) || { printf 'STAGE2_PG_CLOSURE_REFUSED EXACT_RUNTIME\n' >&2; exit 1; }
# Preserve the isolated php.ini pattern; SQL-only profile needs PDO, POSIX, sodium only.
{
  cat /etc/php/8.3/cli/php.ini
  shopt -s nullglob
  for ini in /etc/php/8.3/cli/conf.d/*.ini; do
    case "${ini##*/}" in
      10-pdo.ini|20-pgsql.ini|20-pdo_pgsql.ini|20-posix.ini|20-sodium.ini) cat -- "$ini" ;;
    esac
  done
} >"$R/php.ini"
# Runtime only: no app/bootstrap, no PHP execution in acquisition.
(
  cd "$R"
  find root -type f -print0 | sort -z | xargs -0 sha256sum
  sha256sum php.ini
) >"$R/SHA256SUMS"
chmod 0700 "$A"
[[ -f ${BRIEF_V2_RECEIPT:?} && ! -L $BRIEF_V2_RECEIPT && -O $BRIEF_V2_RECEIPT
   && $(stat -c '%a %s' -- "$BRIEF_V2_RECEIPT") = '600 0' ]] || exit 1
printf 'BRIEF_V2_RUNTIME_DIR=%s\n' "$R" >"$BRIEF_V2_RECEIPT"
ready=true
