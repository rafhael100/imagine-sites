#!/usr/bin/env bash
# Acquisition-only revoke-first candidate. No PostgreSQL start or Laravel bootstrap.
set -euo pipefail
umask 077
export LC_ALL=C DEBIAN_FRONTEND=noninteractive
[[ ${GITHUB_ACTIONS:-} == true && ${GITHUB_EVENT_NAME:-} == pull_request &&
   ${GITHUB_RUN_ATTEMPT:-} == 1 && ${GITHUB_REPOSITORY:-} == rafhael100/imagine-sites &&
   $(grep -cx 'ID=ubuntu' /etc/os-release) == 1 &&
   $(grep -cx 'VERSION_ID="24.04"' /etc/os-release) == 1 &&
   $(dpkg --print-architecture) == amd64 ]] || { echo ONEPAGE_TERMINAL_ACQUISITION_SCOPE_REFUSED >&2; exit 78; }
unset COMPOSER_AUTH GIT_CONFIG_COUNT GH_TOKEN GITHUB_TOKEN SSH_AUTH_SOCK PGPASSWORD PGHOST PGHOSTADDR PGSERVICE
export GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null GIT_TERMINAL_PROMPT=0
A=$(mktemp -d "${RUNNER_TEMP:?}/sites-terminal-acquire.XXXXXXXX")
ready=false
drop_failed() {
  if ! $ready; then
    [[ ${A%/*} = "$RUNNER_TEMP" && ${A##*/} =~ ^sites-terminal-acquire\.[[:alnum:]]{8}$ &&
       ! -L $A && -O $A ]] && rm -rf -- "$A"
  fi
}
trap drop_failed EXIT
P="$A/sites-541"; R="$A/runtime"; T="$R/root"
mkdir -p "$P" "$T" "$A/debs" "$A/apt/partial" "$A/apt-ubuntu/partial"
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
packages=(php8.3-cli php8.3-pgsql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip
  libicu74 libldap2 liblz4-1 libzstd1 libxml2 libssl3t64 libkrb5-3 libreadline8t64 libllvm17t64 libnuma1)
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
# Public application source only; no Portal checkout, Composer credentials or scripts.
chmod 0700 "$A"
sites=99aabc8300b58a1494287a841c71bb302224de44
git -C "$P" init -q --template=
timeout --kill-after=3s 55s /usr/bin/git -C "$P" -c credential.helper= -c http.followRedirects=false \
  fetch -q --depth=1 --no-tags https://github.com/rafhael100/imagine-sites.git "$sites"
git -C "$P" checkout -q --detach FETCH_HEAD
test "$(git -C "$P" rev-parse HEAD)" = "$sites"
test -z "$(git -C "$P" status --porcelain=v1 --untracked-files=all)"
app="$P/apps/sites"
[[ $(realpath -e "$app") = "$app" ]] || exit 1
for p in composer.json composer.lock; do
  test -f "$app/$p" && test ! -L "$app/$p"
  cmp -s "$app/$p" <(git -C "$P" show "$sites:apps/sites/$p")
done
test "$(git -C "$P" hash-object "$app/composer.json")" = 6106165fd77b973f1790c6492958dd1b0f3fd03f
test "$(git -C "$P" hash-object "$app/composer.lock")" = af69afd76d286c8f66d085c6025a54bb82f2c25c
printf '%s  %s\n' c2b50d26ab282213d2ccc6619211795953d5b955f79cda8cc0c1748a97dae9e3 "$app/composer.lock" |
    sha256sum --strict --check --status
for p in "$app"/.env "$app"/.env.testing "$app"/bootstrap/cache/config.php "$app"/bootstrap/cache/routes-v7.php "$app"/vendor; do
  [[ ! -e $p && ! -L $p ]] || exit 1
done
export COMPOSER_HOME="$A/composer-home" COMPOSER_CACHE_DIR="$A/composer-cache" PHP_INI_SCAN_DIR=
mkdir -m 0700 "$COMPOSER_HOME" "$COMPOSER_CACHE_DIR"

# Same version logged by #541; immutable PHAR SHA-256 from its official release page.
# No mutable installer, tags, environment credential, redirect or APT Composer version.
phar="$R/composer-2.11.2.phar"
curl -q --noproxy '*' --proto '=https' --tlsv1.2 -fsS --max-time 30 --max-filesize 6291456 \
  https://getcomposer.org/download/2.11.2/composer.phar -o "$phar"
[[ ! -L $phar && $(realpath -e "$phar") = "$phar" ]] || exit 1
printf '%s  %s\n' dd158c0afd41d15fac89e1b4e47b5d42911fe316c3687cd5edac98319b25d028 "$phar" |
    sha256sum --strict --check --status

# Isolated PHP profile from the predecessor, with no MYSQL modules and no app boot.
{
  cat /etc/php/8.3/cli/php.ini
  shopt -s nullglob
  for ini in /etc/php/8.3/cli/conf.d/*.ini; do
    case "${ini##*/}" in
      10-mysqlnd.ini|20-mysqli.ini|20-pdo_mysql.ini) ;;
      *) cat -- "$ini" ;;
    esac
  done
} >"$R/php.ini"
php=/usr/bin/php8.3
[[ -x $php ]] || exit 1
"$php" -c "$R/php.ini" -r '
if (PHP_MAJOR_VERSION!==8 || PHP_MINOR_VERSION!==3 || PHP_INT_SIZE!==8 ||
    !in_array("pgsql",PDO::getAvailableDrivers(),true)) exit(78);
foreach (["pdo","pdo_pgsql","posix","sodium","mbstring","openssl","curl","dom","zip"] as $e)
    if (!extension_loaded($e)) exit(78);
foreach (["mysqlnd","mysqli","pdo_mysql"] as $e) if (extension_loaded($e)) exit(78);
' || exit 78
timeout --kill-after=3s 240s "$php" -c "$R/php.ini" "$phar" install --working-dir="$app" --no-interaction \
  --no-progress --prefer-source --no-dev --no-scripts --no-plugins >"$A/composer-private.log" 2>&1
test -z "$(git -C "$P" -c core.filemode=true diff --raw "$sites" --)"
cmp -s "$app/composer.lock" <(git -C "$P" show "$sites:apps/sites/composer.lock")
python3 -I -B - "$app" <<'PY'
import json, os, pathlib, re, stat, subprocess, sys
app=pathlib.Path(sys.argv[1]).resolve(strict=True)
v=app/'vendor'
scope='manifest'
def exception_marker(*_):
    sys.stderr.write('STAGE2_COMPOSER_SOURCE_REFUSED '+scope+' UNEXPECTED_IO_OR_SHAPE\n')
sys.excepthook=exception_marker
def refuse(gate):
    raise SystemExit('STAGE2_COMPOSER_SOURCE_REFUSED '+scope+' '+gate)
def check(value,gate):
    if not value: refuse(gate)
def git(p,gate,*args):
    try:
        return subprocess.check_output(['/usr/bin/git','-C',str(p),*args],stderr=subprocess.DEVNULL)
    except (OSError,subprocess.CalledProcessError):
        refuse(gate)
def records(data,gate):
    parts=data.split(b'\0')
    check(parts[-1]==b'',gate)
    return parts[:-1]
def links(entries):
    return {row for row in entries if row.startswith((b'120000 ',b'160000 '))}

NETTE_REF='6bf24518accf32cf845bca305e0b92078700fd01'
NETTE_LINKS=(
    ('tests/Utils/fixtures.finder3/another_subdir/subdir','61f519d04eac5af9dcec3938c655456ee8218036','../subdir'),
    ('tests/Utils/fixtures.finder3/subdir/file.txt','1247f6970a9924a4ea8a68a71148f9d869ab1fea','../file.txt'),
)

def exclude_nette_test_links(path,ref,linked):
    check(ref==NETTE_REF,'NETTE_REF')
    cfg=json.loads((path/'composer.json').read_bytes())
    check(cfg.get('autoload')=={'classmap':['src/'],'psr-4':{'Nette\\':'src'}}
          and 'autoload-dev' not in cfg,'NETTE_AUTOLOAD')
    canon_index={f'120000 {sha} 0\t{rel}'.encode() for rel,sha,_ in NETTE_LINKS}
    canon_tree={f'120000 blob {sha}\t{rel}'.encode() for rel,sha,_ in NETTE_LINKS}
    check(linked==canon_index,'NETTE_INDEX_LINK_SET')
    check(links(records(git(path,'NETTE_GIT_TREE','ls-tree','-r','-z',ref),'NETTE_GIT_TREE'))==canon_tree,'NETTE_TREE_LINK_SET')
    tests=path/'tests/Utils/fixtures.finder3'
    check(tests.resolve(strict=True)==tests and tests.is_dir(),'NETTE_FIXTURE_ROOT')
    opened=[]
    try:
        # Validate both original links and target containment before removing either.
        for rel,sha,target in NETTE_LINKS:
            leaf=path/rel
            parent=leaf.parent
            check(parent.resolve(strict=True)==parent and parent.is_relative_to(tests),'NETTE_PARENT')
            fd=os.open(parent,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
            opened.append((fd,leaf.name))
            orig=os.stat(leaf.name,dir_fd=fd,follow_symlinks=False)
            content=git(path,'NETTE_GIT_BLOB','cat-file','blob',sha)
            raw=os.readlink(os.fsencode(leaf.name),dir_fd=fd)
            check(stat.S_ISLNK(orig.st_mode) and orig.st_uid==os.geteuid() and orig.st_nlink==1
                  and raw==content==target.encode()
                  and leaf.resolve(strict=True).is_relative_to(tests),'NETTE_LINK_PROOF')
        for fd,name in opened:
            os.unlink(name,dir_fd=fd)
    finally:
        for fd,_ in opened:
            os.close(fd)
    raw=records(git(path,'NETTE_GIT_DELTA','-c','core.filemode=true','diff','--raw','--no-abbrev','-z','--no-ext-diff','--no-textconv',ref,'--'),'NETTE_GIT_DELTA')
    expected={(f':120000 000000 {sha} '+('0'*40)+' D').encode():rel.encode()
              for rel,sha,_ in NETTE_LINKS}
    check(len(raw)==4 and dict(zip(raw[::2],raw[1::2]))==expected,'NETTE_EXACT_DELETIONS')
    for rel,_,_ in NETTE_LINKS:
        leaf=path/rel
        check(not leaf.is_symlink() and not leaf.exists(),'NETTE_REMOVED')
    print('STAGE2_NETTE_TEST_LINKS_EXCLUDED 2',flush=True)

lock=json.loads((app/'composer.lock').read_bytes())['packages']
install=json.loads((v/'composer/installed.json').read_bytes())['packages']
check(len(lock)==75 and len(install)==75 and len({x['name'] for x in lock})==75
      and {x['name'] for x in lock}=={x['name'] for x in install},'LOCK_SET')
package_git=set()
package_names={x['name'] for x in lock}
for x in lock:
    scope='manifest'
    name=x['name']
    check(re.fullmatch(r'[a-z0-9_.-]+/[a-z0-9_.-]+',name) and all(p not in ('.','..') for p in name.split('/')),'PACKAGE_NAME')
    scope=name
    ref=x['source']['reference']; url=x['source']['url']
    check(x['source']['type']=='git' and re.fullmatch(r'[0-9a-f]{40}',ref)
          and re.fullmatch(r'https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+',url),'LOCK_REF')
    path=v/name
    check(path.is_dir() and path.resolve()==path and (path/'.git').is_dir() and not (path/'.git').is_symlink(),'GIT_WORKTREE')
    package_git.add(path/'.git')
    check(git(path,'GIT_HEAD','rev-parse','HEAD').strip().decode()==ref,'LOCKED_HEAD')
    # Composer makes declared bins executable, even when upstream recorded 100644.
    # Permit only the observed candidate's deterministic Carbon mode mutation,
    # restore the Git mode, and keep every content and tree check below.
    if x['name']=='nesbot/carbon':
        import hashlib
        check(ref=='ce0e5636fae7d1e99856eb70cae4c719a284aa08' and x.get('bin')==['bin/carbon'],'CARBON_LOCK')
        blob=b'b53ab7385803a001ca55414cbc630e0c8933fbbb'
        check(git(path,'CARBON_GIT_TREE','ls-tree','-z',ref,'--','bin/carbon')==b'100644 blob '+blob+b'\tbin/carbon\0','CARBON_TREE')
        delta=git(path,'CARBON_GIT_DIFF','-c','core.filemode=true','diff','--raw','--no-abbrev','-z','--no-ext-diff','--no-textconv',ref,'--')
        if delta:
            check(delta in {b':100644 100755 '+blob+b' '+oid+b' M\0bin/carbon\0' for oid in (b'0'*40,blob)},'CARBON_MODE_DELTA')
            f=path/'bin/carbon'
            check(f.resolve(strict=True)==f,'CARBON_PATH')
            with os.fdopen(os.open(f,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK),'rb') as stream:
                s=os.fstat(stream.fileno())
                check(stat.S_ISREG(s.st_mode) and stat.S_IMODE(s.st_mode)==0o700 and s.st_uid==os.geteuid()
                      and s.st_nlink==1 and s.st_size==391,'CARBON_FILE')
                data=stream.read(392)
                check(len(data)==391 and hashlib.sha1(b'blob 391\0'+data).hexdigest().encode()==blob,'CARBON_BLOB')
                os.fchmod(stream.fileno(),0o600)
            check(git(path,'CARBON_GIT_DIFF','-c','core.filemode=true','diff','--raw','-z',ref,'--')==b'','CARBON_RESTORED')
            print('STAGE2_CARBON_PINNED_MODE_RESTORED',flush=True)
    check(git(path,'GIT_DIFF','-c','core.filemode=true','diff','--exit-code',ref,'--')==b'','WORKTREE_CLEAN')
    check(git(path,'GIT_UNTRACKED','ls-files','--others','-z')==b'','UNTRACKED_ABSENT')
    indexed=links(records(git(path,'GIT_INDEX','ls-files','--stage','-z'),'GIT_INDEX'))
    if name=='nette/utils':
        try:
            exclude_nette_test_links(path,ref,indexed)
        except (OSError,ValueError):
            refuse('NETTE_FIXTURE_IO')
    else:
        check(not indexed,'TRACKED_LINK_GITLINK_ABSENT')
# Check physical runtime files too, without following any symlinks. Only the
# already-attested per-package Git metadata directories are skipped.
scope='runtime'
def walk_error(_):
    refuse('VENDOR_READ')
for dirname, dirs, files in os.walk(v,followlinks=False,onerror=walk_error):
    root=pathlib.Path(dirname)
    for entry in dirs+files:
        target=root/entry
        rel=target.relative_to(v).parts
        scope='/'.join(rel[:2]) if '/'.join(rel[:2]) in package_names else 'runtime'
        check(not target.is_symlink(),'PHYSICAL_LINK_ABSENT')
        if target in package_git:
            dirs.remove(entry)
    scope='runtime'
print('STAGE2_LOCKED_SOURCES_OK',len(lock),flush=True)
PY
# Generated files are Sites-local. Match PSR-4 roots after the verified
# no-scripts/no-plugins generation without requiring autoload or PHP application.
python3 -I -B - "$P" "$app" <<'PY'
import json, pathlib, sys
def need(v):
    if not v: raise SystemExit('ONEPAGE_TERMINAL_SITES_AUTOLOAD_REFUSED')
root,app=(pathlib.Path(s) for s in sys.argv[1:])
cfg=json.loads((app/'composer.json').read_bytes())
expected={
    'App\\':'app/',
    'Imagine\\Sites\\Domain\\':'../../src/Domain/',
    'Imagine\\Sites\\Production\\':'../../src/Production/',
    'Imagine\\Sites\\Publication\\':'../../src/Publication/',
    'Imagine\\Sites\\Integration\\':'../../src/Integration/',
    'Imagine\\Sites\\Security\\':'../../src/Security/',
}
need(cfg.get('name')=='imagine/sites-app' and cfg.get('autoload',{}).get('psr-4')==expected)
for p in expected.values():
    target=(app/p).resolve(strict=True)
    need(target.is_dir() and target.is_relative_to(root))
for p in ('vendor/autoload.php','vendor/composer/autoload_psr4.php','vendor/composer/autoload_real.php',
          'vendor/composer/autoload_static.php','vendor/composer/installed.json'):
    f=app/p
    need(f.resolve(strict=True)==f and f.is_file() and 0<f.stat().st_size<=8388608)
generated=(app/'vendor/composer/autoload_psr4.php').read_text(encoding='utf-8')
for name in expected:
    need(generated.count("'"+name.replace('\\','\\\\')+"' =>")==1)
PY
# Seal first-class local runtime bytes; repeat this before running or granting
# a SQL handle. The source lock/Git tree and PG package provenance are separate.
(
  cd "$app"
  find vendor -name .git -prune -o -type f -print0 | sort -z | xargs -0 sha256sum
  sha256sum composer.json composer.lock bootstrap/app.php config/database.php
) >"$R/SITES_COMPOSER_SHA256SUMS"
(
  cd "$R"
  find root -type f -print0 | sort -z | xargs -0 sha256sum
  sha256sum php.ini composer-2.11.2.phar SITES_COMPOSER_SHA256SUMS
) >"$R/SHA256SUMS"

receipt="${ONEPAGE_TERMINAL_ACQUIRE_RECEIPT:?}"
parent="${receipt%/*}"
[[ ${parent%/*} = "$RUNNER_TEMP" && ${receipt##*/} = acquisition.receipt &&
   ${parent##*/} =~ ^sites-terminal-outer\.[[:alnum:]]{8}$ &&
   ! -L $parent && -O $parent && $(realpath -e -- "$parent") = "$parent" &&
   $(stat -c '%a' -- "$parent") = 700 &&
   -f $receipt && ! -L $receipt && -O $receipt &&
   $(stat -c '%h' -- "$receipt") = 1 &&
   $(stat -c '%a %s' -- "$receipt") = '600 0' ]] || exit 1
printf 'ONEPAGE_TERMINAL_SITES_ROOT=%s\nONEPAGE_TERMINAL_RUNTIME_DIR=%s\n' "$P" "$R" >"$receipt"
ready=true
