# Djebel Site Development

How to develop and deploy a Djebel **site** — the repo that composes plugins, a theme, config,
and content into a running site. For building the plugins/themes themselves, see
[plugin-guide.md](plugin-guide.md) and [theme-guide.md](theme-guide.md).

## What a site is made of

A site repo holds:
- **Config** — `.ht_djebel/conf/app.ini` (`theme_id`, nav, meta, per-plugin settings).
- **Content** — pages/posts under the static-content dir, uploads, etc.
- **Plugins & theme as git submodules** — each pinned to a specific commit, so the whole site
  is reproducible.

Submodule layout (examples from a real site):
```
.ht_djebel/app/plugins/djebel-contact          → github.com/djebel-app-plugins/djebel-contact
.ht_djebel/app/plugins/djebel-utm
.ht_djebel/app/plugins/djebel-seo
public/dj-content/plugins/djebel-static-content
public/dj-content/themes/djebel-clear           → github.com/djebel-app-themes/djebel-clear
```

A submodule is a **full clone pinned to a commit** — it is your dev copy *and* the deployed
copy at once; the site repo just records *which commit*. There is no separate "dev vs
submodule" copy.

## Clone the site for development
```bash
git clone --recurse-submodules <site-repo-url>
# or, on a clone that's already there:
git submodule update --init --recursive
```

## Develop a plugin or theme

Edit **inside** the submodule — it's a normal repo on its own `main`:
```bash
cd .ht_djebel/app/plugins/djebel-contact
# ...edit...
git commit -am "fix X"
git push                                          # → the plugin's own repo

cd <site root>
git add .ht_djebel/app/plugins/djebel-contact     # bump the pinned commit
git commit -m "Bump djebel-contact"
git push
```

Pull a submodule's latest — **only when you choose to move the pin** (this is the one
dev-only "recursive" operation):
```bash
git submodule update --remote .ht_djebel/app/plugins/djebel-contact
```

**Shared across sites?** The submodule is one working copy of a repo *other* sites also pin, so
after you push the plugin's repo (above) every other site still points at the old commit — bump
its pin there too:
```bash
cd <other site root>                                # another site that pins the same repo
git submodule update --remote .ht_djebel/app/plugins/djebel-contact
git add .ht_djebel/app/plugins/djebel-contact
git commit -m "Bump djebel-contact"
git push
```
Do it per site that uses it. Pin each site **independently** — a shared lib does *not* force
every site onto the same commit: a stable site can stay on an older pin while another rides
latest. For an *exact* commit instead of "latest `main`", `git fetch && git checkout <sha>`
inside the submodule instead of `--remote`.

## Pin to a tag (release)

Submodules pin to a **commit**; a tag is just the handle you use to land on one.
```bash
cd .ht_djebel/app/plugins/djebel-contact
git checkout v1.0.0
cd <site root>
git add .ht_djebel/app/plugins/djebel-contact     # records v1.0.0's commit SHA
git commit -m "Pin djebel-contact @ v1.0.0"
git push
```
Move the pin later by checking out a newer tag in the submodule and re-`git add`-ing it.

## Add a new plugin/theme submodule
```bash
# commit + push the plugin's own repo FIRST, so the recorded pin exists on the remote
git submodule add https://github.com/djebel-app-plugins/<name>.git .ht_djebel/app/plugins/<name>
git commit -m "Add <name> as submodule"
```

## Converting an existing folder into a submodule (one-time, safe)

When a plugin/theme folder **already exists** in place (plain files, or an un-registered
nested repo) and you're turning it into a submodule, **never `rm -rf` it** — the working copy
may hold **uncommitted or unpushed changes** that a delete would destroy for good.

1. Salvage its own history first — commit and push from inside the folder:
   ```bash
   cd .ht_djebel/app/plugins/djebel-contact
   git status                 # anything uncommitted?
   git log --oneline @{u}..   # anything unpushed?
   git add -A && git commit -m "wip" && git push   # if needed
   cd -                       # back to site root
   ```
2. Temp-rename it aside — keep a `000` backup, don't delete — which frees the path:
   ```bash
   mv .ht_djebel/app/plugins/djebel-contact .ht_djebel/app/plugins/djebel-contact000
   ```
3. Bring it in as a submodule at the pinned commit:
   ```bash
   git submodule update --init --recursive   # on a clone/deploy that already tracks it
   # — or, registering it for the first time:
   git submodule add https://github.com/djebel-app-plugins/djebel-contact.git .ht_djebel/app/plugins/djebel-contact
   ```
4. Verify the site renders, **diff the backup** for anything you missed, then drop it:
   ```bash
   diff -r .ht_djebel/app/plugins/djebel-contact000 .ht_djebel/app/plugins/djebel-contact
   rm -rf .ht_djebel/app/plugins/djebel-contact000   # only once you're satisfied
   ```

The `000` suffix is just a throwaway backup marker — `mv` it back if anything looks off.
Repeat per folder being converted; afterwards the regular deploy two-liner handles everything.

## Deploy (prod via git)

⚠️ A plain `git pull` updates the **site** repo but **does not touch submodules** — you'd ship
new site code with *stale* plugins and not notice. Always pair it:
```bash
git pull
git submodule update --init --recursive
```
- `--init` materializes any newly-added submodule (first deploy after you add one)
- `--recursive` handles nested submodules
- the update checks each submodule out at its **pinned commit** — deterministic, never "latest"

> First deploy on a server that still has those plugins as **plain folders**? Do the one-time
> temp-rename from [*Converting an existing folder into a submodule*](#converting-an-existing-folder-into-a-submodule-one-time-safe)
> first, or `--init` will choke on the non-empty dirs (and never `rm` them — they may hold
> uncommitted/unpushed work).

To let `git pull` auto-update *existing* submodules, set once on the server:
```bash
git config submodule.recurse true
```
…but keep the two-line form in the deploy script anyway: `submodule.recurse` won't
*initialize* a brand-new submodule, so the two-liner is the always-correct superset.

### deploy.sh
```bash
#!/usr/bin/env bash
set -euo pipefail

git pull --ff-only                        # fail rather than create a merge commit
git submodule sync --recursive            # pick up any submodule URL changes
git submodule update --init --recursive   # check each submodule out at its pinned commit

echo "Deploy synced: site + all submodules at their pinned commits."
```
Note: if a pin references a commit that was never pushed to the submodule's remote,
`git submodule update --init` **fails here** under `set -e` — that's the loud failure you
want, caught at deploy instead of silently shipping a missing plugin.

## dev vs prod — the one distinction that matters

| | Operation | What it does |
|---|---|---|
| **dev** | `git submodule update --remote` | pull a submodule's **latest**, move the pin forward (your choice) |
| **prod** | `git submodule update --init --recursive` | land on the **pinned** commit only — deterministic |

The only strictly dev-only act is chasing `--remote` (latest). Everything prod does is
pinned and reproducible.
