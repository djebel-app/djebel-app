# Djebel Site Deployment

How to lay out, wire up, and ship a Djebel **site** on a server. For the git/submodule
development workflow (editing plugins, moving pins, the deploy.sh two-liner), see
[../developers/site-development.md](../developers/site-development.md).

## The four levels of a site

| Level | What | Where | Changes |
|---|---|---|---|
| 1. Core | djebel-app (checkout or phar) | sibling `djebel-app/`, or `.ht_djebel/app/djebel-app.phar` | on upgrade only |
| 2. Site private | config + private plugins | `.ht_djebel/` (conf/, app/plugins/) | at deploy |
| 3. Site public | loader, public plugins, theme, content | `public/` (index.php, .htaccess, dj-content/) | at deploy |
| 4. Runtime data | cache, logs, plugin data | `.ht_djebel/data/` (gitignored) | at runtime — must survive deploys |

The standard layout:

```
mysite/
├── .ht_djebel/              # level 2 — NEVER web-accessible
│   ├── conf/app.ini
│   ├── app/plugins/         # private plugins
│   └── data/                # level 4 — cache, logs, plugin data
├── public/                  # level 3 — THE ONLY web-accessible dir
│   ├── index.php            # loader (a copy — see Symlink policy)
│   ├── .htaccess
│   └── dj-content/          # public plugins, themes, content data
└── djebel-app/              # level 1 (optional — phar and env var work too)
```

## Docroot wiring

| Option | How | When |
|---|---|---|
| vhost | `DocumentRoot /home/user/mysite/public` | you control the vhost — simplest, no symlinks involved |
| symlink | `public_html -> mysite/public` | shared hosting with a fixed docroot name |
| home-dir split | `~/.ht_djebel` + loader in `~/public_html/` | shared hosting where symlinks are blocked — the loader scans one level up and finds it |
| all-in-one (flat) | docroot = the site dir; `.ht_djebel/` sits INSIDE it | no vhost control, subdir installs, quick drops — convenient, but read the warning below |

⚠️ **The flat layout's security depends 100% on the web server blocking `.ht`-prefixed
names.** That is exactly what the `.ht_` prefix is for — hardened servers deny anything
starting with `.ht`, and the private dir rides the same convention as `.htaccess`/
`.htpasswd`. But don't assume it — two stock-config gaps are common:

- Apache's shipped rule (`<Files ".ht*">`) matches file *basenames* only: it denies
  `/.ht_djebel` itself but NOT `/.ht_djebel/conf/app.ini` (basename `app.ini`).
- nginx ships NO dotfile block at all — distro configs usually add one, stock doesn't.

Make the site self-protecting instead of hoping — one line in the site's own
`.htaccess` blocks the `.ht*`, `.env*`, and `.git*` subtrees (private dir, env files,
and the repo — a served `/.git/` leaks the entire site source, config included):

```apache
RedirectMatch 404 "/\.(ht|env|git)"
```

(nginx: `location ~ /\.(ht|env|git) { return 404; }` in the server block. 404, not
403 — a probe shouldn't even learn the name exists.) Belt and suspenders: a deny-all
`.htaccess` inside `.ht_djebel/` too (`Require all denied`), and verify after
deploy — `curl -I https://example.com/.ht_djebel/conf/app.ini` must NOT be 200.

## The loader contract

The site's `public/index.php` (the loader) does two jobs, in this order:

1. **Pins the private dir** — scans for `.ht_djebel` starting from `__DIR__` and
   `putenv('DJEBEL_APP_PRIVATE_DIR=...')` BEFORE the core runs.
2. **Locates the core bootstrap** — first match wins:
   `DJEBEL_APP_PKG` env (full path to a .phar) → `.ht_djebel/app/djebel-app.phar` →
   sibling `djebel-app/index.php` (one or two levels up) → the dev checkout.

The putenv in step 1 is **load-bearing under a symlinked docroot**: PHP resolves
symlinks in `__FILE__`/`__DIR__`, so the loader always lands in the *real* site tree —
but the core's own fallback scan starts from `SCRIPT_FILENAME`, which keeps the symlink
route as the webserver passed it, and would look in the wrong parents. Keep the putenv;
never rely on the core scan alone for a symlinked site.

## Symlink policy — pointers yes, entry files no

**Never symlink `public/index.php` or `.htaccess`.** The same `__DIR__` resolution that
makes docroot symlinks safe breaks a symlinked loader: inside
`public/index.php -> /opt/djebel/loader.php`, `__DIR__` is `/opt/djebel` and the
`.ht_djebel` scan searches the wrong tree. Symlinks also die in zips, FTP transfers,
and standalone clones (a link out of the repo points at nothing on the server).
These two files are **copies** — keep the loader dumb, stable, and version-stamped so
drift across sites is visible.

**Do symlink directory-level pointers** — that's where atomic switching lives:

```
public_html      -> mysite/public                       # docroot
current          -> releases/20260803-1                 # release flip
djebel-app       -> /opt/djebel/djebel-app-0.0.5        # shared core, pinned per site
djebel-app.phar  -> djebel-app-0.0.5.phar               # the loader wants the exact name
```

## Dot-prefixed files are easy to lose

Half of a djebel site starts with a dot — `.ht_djebel/`, `.htaccess`, `.env*` — and
most file managers and FTP clients HIDE dot entries by default. A "complete" copy or
upload that silently skipped them is the classic broken deploy: the site renders, but
config, private plugins, and rewrites are gone.

Show hidden files first:

| Where | How |
|---|---|
| macOS Finder | `Cmd+Shift+.` (toggles, remembered per user) |
| Windows Explorer | View → Show → Hidden items (Windows hides by file *attribute*, not by the dot — dot names show by default, but enable it anyway for attribute-hidden files) |
| Linux — GNOME Files / Dolphin / Thunar | `Ctrl+H` (toggle) |
| FileZilla | Server → Force showing hidden files |
| Cyberduck | View → Show Hidden Files |
| any shell | `ls -la` |

⚠️ Shell globs skip dot entries too: `cp -r site/*`, `zip -r site.zip *`, `mv *` all
miss `.ht_djebel` and `.htaccess`. Copy the *directory*, not its glob — `cp -r site/
dest/`, `zip -r site.zip site/`, or `rsync -a site/ dest/` (rsync includes dot files).

## Deployment strategies

| # | Strategy | How | Best for | Watch out |
|---|---|---|---|---|
| 1 | In-place git pull | `git pull --ff-only` + `git submodule update --init --recursive` | your own VPS | needs git + repo auth on the server; not atomic |
| 2 | Atomic releases | `releases/N/` + `shared/` data + `current` symlink flip | rollback in seconds | data must live outside the release; reload php-fpm after a flip (opcache caches realpaths) |
| 3 | Bundle artifact | `tools/bundle.php` zip (phar + plugins + theme) → scp → unzip → flip | shared hosting, no git on the server | loader needs the exact `djebel-app.phar` name — use the symlink pin |
| 4 | Shared core | `/opt/djebel/djebel-app-x.y.z/` + per-site `djebel-app` symlink or `DJEBEL_APP_PKG` | many sites per server | always VERSIONED dirs — one unversioned shared core means one upgrade breaks every site |

Strategies compose: e.g. bundle artifacts unzipped into `releases/N` (3 + 2), or
atomic releases whose sites all pin a shared core (2 + 4).

Atomic release layout:

```
mysite/
├── releases/
│   ├── 20260801-1/
│   └── 20260803-1/          # each: .ht_djebel/ + public/ (data symlinked to shared/)
├── shared/
│   └── data/                # level 4 — survives every deploy
├── current -> releases/20260803-1
└── public_html -> current/public
```

Deploy = materialize the new release, link `shared/data` in, flip `current`, reload
php-fpm. Rollback = flip back.

## Per-environment config

One file per environment — no layering, no merge order:

- `DJEBEL_APP_ENV` (or `APP_ENV`) unset → `conf/.env` loads.
- `DJEBEL_APP_ENV=prod` and `conf/.env_prod` exists → **only** `.env_prod` loads
  (it fully replaces `.env`); otherwise `.env` loads.

Set the env name in the vhost (`SetEnv DJEBEL_APP_ENV prod`), the php-fpm pool
(`env[DJEBEL_APP_ENV] = prod`), or the loader — **never inside `.env` itself**: the
env name is read before the file loads, so a self-declared env only takes effect from
a worker's second request.
