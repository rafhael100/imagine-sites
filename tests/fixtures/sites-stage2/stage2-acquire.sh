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
check(len(lock)>0 and {x['name'] for x in lock}=={x['name'] for x in install},'LOCK_SET')
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
cat /etc/php/8.3/cli/php.ini /etc/php/8.3/cli/conf.d/*.ini >"$R/php.ini"
(
  cd "$R"
  find root -type f -print0 | sort -z | xargs -0 sha256sum
  sha256sum php.ini
) >"$R/SHA256SUMS"
printf 'STAGE2_PORTAL_ROOT=%s\nSTAGE2_RUNTIME_DIR=%s\n' "$P" "$R" >>"${GITHUB_ENV:?}"
