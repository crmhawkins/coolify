# Upgrading Coolify on this fork

This fork (`crmhawkins/coolify`) deliberately runs with **auto-update
disabled**. The reason is simple: the fork lives as a set of
cherry-picked PHP/Blade/YAML patches that `copy.sh` injects on top of
the vanilla `ghcr.io/coollabsio/coolify` image. An auto-update would
recreate the container from a fresh upstream image and silently wipe
every customisation between two midnights.

This document explains how to safely take a newer upstream version
when you actually want one.

## TL;DR

When you want to update Coolify to a newer version:

1. **Ask Claude for the upgrade.** Don't run `update-coolify.sh`
   blindly with a new tag.
2. Claude reviews the upstream changelog between the current pinned
   tag and the target tag, flags anything that conflicts with the
   fork, and pushes any required compatibility commits to `v4.x` first.
3. Claude gives you the exact command to run on each host:
   ```
   /root/update-coolify.sh 4.0.0-beta.XXX
   ```
4. Run it on **one** host first, verify everything works, then run
   it on the second host.
5. If anything breaks, run `--rollback` and tell Claude what failed.

## When you should ask for an upgrade

- **A security advisory** affects your pinned version. Coollabs
  publishes those on the GitHub releases page and on their Discord.
- **You need a specific feature** that landed upstream (a new OAuth
  provider, a new database driver, a new buildpack, etc.) and isn't
  in the fork.
- **Routine refresh.** Every 3–6 months it's healthy to take the
  current upstream stable so the gap with your fork doesn't grow
  too large to ever close again.

## When you should NOT upgrade

- **Right before a client launch.** Updates can introduce regressions
  even when the changelog looks innocent.
- **Without telling Claude first.** The whole point of pinning is
  that you control when and how upstream changes hit your hosts.
- **Without a fresh backup.** The script makes one pre-flight, but a
  separate offline backup of `/data/coolify` is cheap insurance.

## What lives where

| File | Purpose |
| --- | --- |
| `/data/coolify/source/.env` | Has `LATEST_IMAGE=4.0.0-beta.<tag>`. This is what pins the image. |
| `/data/coolify/source/upgrade.sh` | Replaced with a no-op stub on this fork's hosts. The real upstream upgrade script is preserved as `upgrade.sh.original-backup` for emergency recovery only. |
| `/root/update-coolify.sh` | The safe, manual upgrade script. Sync'd from `scripts/update-coolify.sh` in this repo by `copy.sh`. |
| `/root/copy.sh` | Re-applies the fork's cherry-picked files on top of the running container. Called automatically by `update-coolify.sh` after a successful image swap. |
| `/data/coolify-backups/manual/upgrade-<timestamp>/` | Pre-flight backup created at the start of every upgrade run. Contains a Postgres dump and a copy of `.env`. |
| `/root/update-coolify.log` | Append-only log of every `update-coolify.sh` invocation. |

## What `update-coolify.sh` actually does

Subcommands:

```bash
/root/update-coolify.sh <tag>            # Upgrade to that tag
/root/update-coolify.sh --current        # Show what's pinned and running
/root/update-coolify.sh --rollback       # Restore the most recent backup
/root/update-coolify.sh --rollback DIR   # Restore a specific backup
```

Upgrade flow:

1. Read the current `LATEST_IMAGE` from `.env`. That's the rollback
   target if anything fails.
2. Pre-flight backup to `/data/coolify-backups/manual/upgrade-<ts>/`:
   Postgres dump, `.env`, both compose files.
3. `docker pull` the requested image. If this fails, nothing else
   has been touched.
4. Rewrite `LATEST_IMAGE` in `.env`.
5. Stop, remove and recreate **only** the `coolify` container with
   `--no-deps`. Postgres, Redis, Soketi and Traefik proxy are not
   touched.
6. Wait up to 120 seconds for the new container to report `healthy`
   via Docker's healthcheck.
7. Re-run `/root/copy.sh` to re-inject the fork's files on top of
   the fresh image.
8. One more health check after `copy.sh` to confirm the whole chain
   is alive.

If **any** of steps 5–7 fail, the script automatically rolls back
the image tag, re-pulls the previous image and recreates the
container. The Postgres dump is **never** auto-restored — if a
migration on the new image left schema changes behind, the operator
decides whether to restore. The script prints the exact `psql`
command for manual restore.

Safety rails:

- Refuses to run as non-root.
- Refuses to run outside `/data/coolify/source`.
- A lock file at `/var/run/update-coolify.lock` prevents concurrent
  invocations on the same host.
- Every destructive command is announced before it runs.
- Whole stdout/stderr stream is `tee`'d to `/root/update-coolify.log`.

## Two-host upgrade procedure

You currently run two hosts (the `81` and the `79`). Always upgrade
them sequentially, never in parallel:

1. **Pick the less-critical host first.** Run the upgrade there.
2. **Verify.** Open the Coolify UI on that host:
   - Dashboard loads, projects list is populated.
   - Click into one random project, one random WordPress, one random
     Laravel-rootkit, one random database. They should all show
     "Running (healthy)".
   - Run a no-op redeploy on a non-production project as a smoke test.
   - Check that the fork-only menus (Backups, Usuarios, Clientes)
     still appear in the sidebar.
3. **Wait at least 30 minutes** before touching the second host.
   This is the window where any regression that escapes the
   healthcheck has a chance to bite you.
4. **Run the upgrade on the second host** with the same command.
5. Verify the same way.

## What to do if something breaks

In order:

1. **Don't panic.** The proxy and the project containers are
   independent of the Coolify container. Your sites stay up even if
   the Coolify UI itself is broken.
2. **Roll back the Coolify container:**
   ```
   /root/update-coolify.sh --rollback
   ```
   This restores the previous image tag and `.env`. Your sites stay
   up the whole time because their containers are independent.
3. **Check the rollback worked:**
   ```
   /root/update-coolify.sh --current
   ```
   Should show the previous tag.
4. **Tell Claude.** Paste:
   - The upgrade log: `cat /root/update-coolify.log | tail -200`
   - The output of `--current`
   - Any specific error you saw in the UI
5. **If even the rollback failed**, the database might need a
   manual restore. The exact `psql` command is printed by the
   script and saved in the log. Don't run it without checking with
   Claude first — auto-restoring on top of a partially-migrated
   database can corrupt things worse.

## What `update-coolify.sh` does NOT do

- Touch any project, service or app container.
- Touch the Traefik proxy (`coolify-proxy`).
- Touch volumes (`coolify-db`, `coolify-redis`).
- Run `php artisan migrate` directly (migrations run inside the new
  container via the upstream entrypoint).
- Push anything to git.
- Decide which version to take. That's the operator's job, with
  Claude's review.

## Forbidden actions

Some things look like they would help but will silently break the
fork. Don't do them:

- **Don't set `is_auto_update_enabled` to `true` from the Coolify
  UI.** It triggers the upstream auto-updater and wipes the fork
  on the next run.
- **Don't restore `upgrade.sh.original-backup` over `upgrade.sh`.**
  That re-arms the upstream auto-updater.
- **Don't remove the `LATEST_IMAGE=` line from `.env`.** Without it,
  any `docker compose up -d coolify` falls back to `:latest` and
  pulls whatever is currently the upstream tip.
- **Don't run `docker compose pull` followed by `docker compose
  up -d`** to update Coolify. That bypasses the fork's safety net
  entirely. Use `update-coolify.sh`.

## How the fork stays in sync with upstream

This is **not** a long-running fork that constantly merges from
upstream. It's a pinned version with cherry-picked patches:

- The fork tracks a specific upstream tag (currently `4.0.0-beta.463`).
- All fork-specific work lives on top of that tag as commits on the
  `v4.x` branch.
- When upstream releases something worth taking, Claude does a
  manual review of the diff between tags and either:
  - Cherry-picks specific upstream commits on top of the fork.
  - Bumps the pinned tag and reconciles any conflicts in the
    cherry-picked files.
- The result lands as one or more commits on `v4.x`, which then get
  deployed to the hosts via the normal `./deploy.sh && ./copy.sh`
  flow, and finally the image bump goes through `update-coolify.sh`.

Operator-side this means: **don't try to merge upstream yourself**
unless you really know what you're doing. Ask Claude.
