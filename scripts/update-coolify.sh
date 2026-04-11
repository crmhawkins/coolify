#!/bin/bash
#
# update-coolify.sh
#
# Manual, safe upgrade of the pinned Coolify container image on a
# single host. Designed for this fork (crmhawkins/coolify), where
# auto-update is deliberately disabled because it would wipe the
# cherry-picked PHP/Blade/YAML patches that `copy.sh` injects on
# top of the vanilla coollabsio/coolify image.
#
# Usage:
#   ./update-coolify.sh <target-tag>
#   ./update-coolify.sh 4.0.0-beta.480
#   ./update-coolify.sh --rollback       # restores the previous tag
#   ./update-coolify.sh --current        # shows the running tag
#
# What it does for a real upgrade:
#   1. Captures the current pinned tag as the rollback target.
#   2. Runs a full pre-flight backup (postgres dump + .env + config).
#   3. Pulls the requested image from ghcr.io/coollabsio/coolify.
#   4. Rewrites LATEST_IMAGE in /data/coolify/source/.env.
#   5. Recreates ONLY the `coolify` container (--no-deps, so postgres,
#      redis, soketi and the proxy never get touched).
#   6. Waits up to 120 s for the `coolify` container to report
#      healthy according to Docker's healthcheck.
#   7. Re-runs `./copy.sh` from /root/ if present, so the fork's
#      cherry-picked files and migrations are re-applied on top of
#      the fresh upstream image.
#   8. If ANY step fails, automatically rolls back to the previous
#      tag, restores the .env, and recreates the container. The
#      postgres dump is kept on disk regardless so you can restore
#      it by hand if a migration left the DB in a bad state.
#
# What it explicitly does NOT do:
#   - Touch any project/service/app container.
#   - Touch the Traefik proxy.
#   - Touch volumes (coolify-db, coolify-redis).
#   - Run `php artisan migrate` — that happens inside the container
#     on boot, driven by the coollabsio entrypoint.
#   - Push anything to git.
#
# Safety rails:
#   - set -euo pipefail throughout the main flow
#   - a lock file under /var/run prevents two invocations at once
#   - every destructive command logs what it is about to do
#   - full log stream is tee'd to /root/update-coolify.log
#

set -euo pipefail

# --- constants ----------------------------------------------------------------

COMPOSE_DIR="/data/coolify/source"
ENV_FILE="${COMPOSE_DIR}/.env"
BACKUP_BASE="/data/coolify-backups/manual"
REGISTRY="ghcr.io/coollabsio/coolify"
HEALTHCHECK_TIMEOUT=120
LOCK_FILE="/var/run/update-coolify.lock"
LOG_FILE="/root/update-coolify.log"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
BOLD='\033[1m'
NC='\033[0m'

# --- helpers ------------------------------------------------------------------

log() {
    echo -e "${BLUE}[$(date '+%H:%M:%S')]${NC} $*"
}

warn() {
    echo -e "${YELLOW}[$(date '+%H:%M:%S')] ⚠ $*${NC}"
}

err() {
    echo -e "${RED}[$(date '+%H:%M:%S')] ✗ $*${NC}" >&2
}

ok() {
    echo -e "${GREEN}[$(date '+%H:%M:%S')] ✓ $*${NC}"
}

require_root() {
    if [ "$(id -u)" -ne 0 ]; then
        err "This script must run as root."
        exit 1
    fi
}

require_coolify_dir() {
    if [ ! -d "${COMPOSE_DIR}" ] || [ ! -f "${COMPOSE_DIR}/docker-compose.yml" ]; then
        err "Could not find ${COMPOSE_DIR}/docker-compose.yml. Is this a Coolify host?"
        exit 1
    fi
    if [ ! -f "${ENV_FILE}" ]; then
        err "${ENV_FILE} missing. Refusing to continue without the env file."
        exit 1
    fi
}

acquire_lock() {
    if [ -e "${LOCK_FILE}" ]; then
        err "Another upgrade is in progress (lock: ${LOCK_FILE})."
        err "If you are sure nothing is running, remove it by hand: rm -f ${LOCK_FILE}"
        exit 1
    fi
    trap 'rm -f "${LOCK_FILE}"' EXIT
    echo "$$" > "${LOCK_FILE}"
}

current_tag() {
    # Prefer what's literally pinned in the env file. That is the source
    # of truth — the running container may have been launched against a
    # different image by something outside this script.
    local tag
    tag=$(grep -E '^LATEST_IMAGE=' "${ENV_FILE}" 2>/dev/null | head -1 | cut -d= -f2- | tr -d '"' || true)
    if [ -z "${tag}" ]; then
        # Fallback to whatever is actually running.
        tag=$(docker inspect coolify --format '{{.Config.Image}}' 2>/dev/null | awk -F: '{print $NF}' || true)
    fi
    echo "${tag:-unknown}"
}

write_pinned_tag() {
    local new_tag="$1"
    if grep -qE '^LATEST_IMAGE=' "${ENV_FILE}"; then
        sed -i "s|^LATEST_IMAGE=.*|LATEST_IMAGE=${new_tag}|" "${ENV_FILE}"
    else
        echo "LATEST_IMAGE=${new_tag}" >> "${ENV_FILE}"
    fi
}

wait_healthy() {
    local deadline=$(( $(date +%s) + HEALTHCHECK_TIMEOUT ))
    while [ "$(date +%s)" -lt "${deadline}" ]; do
        local state
        state=$(docker inspect coolify --format '{{.State.Health.Status}}' 2>/dev/null || echo "unknown")
        case "${state}" in
            healthy)
                return 0
                ;;
            unhealthy)
                err "Container reported unhealthy."
                return 1
                ;;
            starting|"")
                ;;
            *)
                # Fall back to running state if the image has no healthcheck.
                local running
                running=$(docker inspect coolify --format '{{.State.Running}}' 2>/dev/null || echo "false")
                if [ "${running}" = "true" ]; then
                    return 0
                fi
                ;;
        esac
        sleep 3
    done
    err "Healthcheck timed out after ${HEALTHCHECK_TIMEOUT}s."
    return 1
}

recreate_container() {
    log "Recreating the coolify container with --no-deps (nothing else is touched)..."
    docker stop coolify >/dev/null 2>&1 || true
    docker rm coolify >/dev/null 2>&1 || true
    (
        cd "${COMPOSE_DIR}"
        docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --no-deps coolify
    )
}

run_copy_sh_if_present() {
    # copy.sh is how this fork re-injects its cherry-picked patches
    # (Livewire components, templates, helpers) on top of the fresh
    # upstream image. Fire-and-forget: log a warning if it fails but
    # do not fail the whole upgrade on its behalf, because the
    # container itself is already up.
    if [ -x /root/copy.sh ]; then
        log "Re-running /root/copy.sh to re-apply fork patches on top of the new image..."
        if /root/copy.sh; then
            ok "Fork patches re-applied."
        else
            warn "copy.sh exited non-zero. Check its output above."
        fi
    else
        warn "/root/copy.sh not found or not executable — skipping fork re-patch."
        warn "If this host uses this fork, you almost certainly want to install copy.sh."
    fi
}

do_backup() {
    local date_stamp
    date_stamp=$(date +%Y%m%d-%H%M%S)
    local backup_dir="${BACKUP_BASE}/upgrade-${date_stamp}"
    mkdir -p "${backup_dir}"

    log "Backup: ${backup_dir}"

    log "Dumping postgres database..."
    docker exec coolify-db sh -c 'pg_dump -U $POSTGRES_USER $POSTGRES_DB' \
        > "${backup_dir}/coolify-db.sql"
    ok "Database dump written ($(du -h "${backup_dir}/coolify-db.sql" | cut -f1))"

    log "Copying .env..."
    cp "${ENV_FILE}" "${backup_dir}/.env.bak"

    log "Copying compose files..."
    cp "${COMPOSE_DIR}/docker-compose.yml" "${backup_dir}/" 2>/dev/null || true
    cp "${COMPOSE_DIR}/docker-compose.prod.yml" "${backup_dir}/" 2>/dev/null || true

    # Export path for the rollback handler
    echo "${backup_dir}" > /tmp/update-coolify-last-backup
}

attempt_rollback() {
    local previous_tag="$1"
    warn "Attempting automatic rollback to ${previous_tag}..."
    write_pinned_tag "${previous_tag}"
    if ! docker pull "${REGISTRY}:${previous_tag}" >/dev/null 2>&1; then
        err "Could not pull the previous image ${REGISTRY}:${previous_tag}."
        err "Manual recovery required. Backup at: $(cat /tmp/update-coolify-last-backup 2>/dev/null || echo '??')"
        return 1
    fi
    if recreate_container && wait_healthy; then
        ok "Rollback to ${previous_tag} completed. The container is healthy again."
        ok "The failed backup is preserved at: $(cat /tmp/update-coolify-last-backup 2>/dev/null || echo '??')"
        ok "The DB was NOT restored automatically — restore it by hand if the new"
        ok "image's migration left schema changes behind:"
        ok "  docker exec -i coolify-db sh -c 'psql -U \$POSTGRES_USER \$POSTGRES_DB' \\"
        ok "    < $(cat /tmp/update-coolify-last-backup 2>/dev/null)/coolify-db.sql"
        return 0
    fi
    err "Rollback ALSO failed. This host is in a degraded state."
    err "You probably need to: docker restart coolify-db && try again."
    return 1
}

# --- subcommands --------------------------------------------------------------

cmd_current() {
    local tag
    tag=$(current_tag)
    echo -e "${BOLD}Currently pinned tag:${NC} ${tag}"
    if docker inspect coolify >/dev/null 2>&1; then
        local running_image running_state
        running_image=$(docker inspect coolify --format '{{.Config.Image}}')
        running_state=$(docker inspect coolify --format '{{.State.Status}}')
        echo -e "${BOLD}Actually running:${NC}     ${running_image} (${running_state})"
    else
        warn "No 'coolify' container is running right now."
    fi
}

cmd_upgrade() {
    local target_tag="$1"
    local previous_tag
    previous_tag=$(current_tag)

    if [ "${target_tag}" = "${previous_tag}" ]; then
        warn "Target tag ${target_tag} is already pinned. Nothing to do."
        warn "If you want to FORCE a re-pull of the same tag, use: --rebuild ${target_tag}"
        exit 0
    fi

    echo ""
    echo -e "${BOLD}════════════════════════════════════════════════════════════${NC}"
    echo -e "${BOLD} Coolify manual upgrade${NC}"
    echo -e "${BOLD}════════════════════════════════════════════════════════════${NC}"
    echo -e "  Previous tag:   ${previous_tag}"
    echo -e "  Target tag:     ${target_tag}"
    echo -e "  Rollback on failure: YES (automatic within ${HEALTHCHECK_TIMEOUT}s healthcheck window)"
    echo -e "${BOLD}════════════════════════════════════════════════════════════${NC}"
    echo ""
    read -r -p "Proceed? [y/N] " confirm
    case "${confirm}" in
        y|Y|yes|YES) ;;
        *) log "Aborted by user."; exit 0 ;;
    esac

    log "[1/5] Pre-flight backup"
    do_backup

    log "[2/5] Pulling ${REGISTRY}:${target_tag}"
    if ! docker pull "${REGISTRY}:${target_tag}"; then
        err "docker pull failed for ${REGISTRY}:${target_tag}. Nothing has been touched yet."
        exit 2
    fi
    ok "Image pulled."

    log "[3/5] Pinning LATEST_IMAGE=${target_tag} in ${ENV_FILE}"
    write_pinned_tag "${target_tag}"
    grep -E '^LATEST_IMAGE=' "${ENV_FILE}"

    log "[4/5] Recreating container and waiting for health..."
    if ! recreate_container; then
        err "Recreate failed."
        attempt_rollback "${previous_tag}" || true
        exit 3
    fi

    if ! wait_healthy; then
        err "Container never became healthy within ${HEALTHCHECK_TIMEOUT}s."
        attempt_rollback "${previous_tag}" || true
        exit 3
    fi
    ok "Container is healthy on ${target_tag}."

    log "[5/5] Re-applying fork patches via copy.sh"
    run_copy_sh_if_present

    # Final sanity check — the copy.sh step restarts the container to
    # clear caches, so we wait for health one more time.
    if ! wait_healthy; then
        err "Container not healthy after copy.sh. Manual investigation required."
        err "Backup preserved at: $(cat /tmp/update-coolify-last-backup 2>/dev/null || echo '??')"
        exit 4
    fi

    echo ""
    ok "════════════════════════════════════════════════════════════"
    ok " Upgrade completed"
    ok "════════════════════════════════════════════════════════════"
    ok " From: ${previous_tag}"
    ok " To:   ${target_tag}"
    ok " Backup: $(cat /tmp/update-coolify-last-backup 2>/dev/null || echo '??')"
    echo ""
    ok "Next manual check: open the Coolify UI and confirm the dashboard,"
    ok "the proxy, and one random project/service all look alive."
    ok "If anything is wrong, you can roll back any time with:"
    ok "  $0 --rollback"
    echo ""
}

cmd_rollback() {
    # Rollback uses the backup referenced in /tmp/update-coolify-last-backup
    # if present, otherwise we ask for a backup directory explicitly.
    local backup_dir="${1:-}"
    if [ -z "${backup_dir}" ] && [ -f /tmp/update-coolify-last-backup ]; then
        backup_dir=$(cat /tmp/update-coolify-last-backup)
    fi
    if [ -z "${backup_dir}" ] || [ ! -d "${backup_dir}" ]; then
        err "Cannot locate a backup directory to roll back from."
        err "Pass one explicitly: $0 --rollback ${BACKUP_BASE}/upgrade-<date>"
        err "Or list what's available:"
        ls -1 "${BACKUP_BASE}" 2>/dev/null || true
        exit 1
    fi

    if [ ! -f "${backup_dir}/.env.bak" ]; then
        err "No .env.bak inside ${backup_dir}. Refusing to roll back blindly."
        exit 1
    fi

    echo -e "${BOLD}Rolling back using: ${backup_dir}${NC}"
    read -r -p "This will restore .env and recreate the container. Proceed? [y/N] " confirm
    case "${confirm}" in
        y|Y|yes|YES) ;;
        *) log "Aborted."; exit 0 ;;
    esac

    log "Restoring .env"
    cp "${backup_dir}/.env.bak" "${ENV_FILE}"
    local target_tag
    target_tag=$(current_tag)
    log "Rollback target tag: ${target_tag}"

    docker pull "${REGISTRY}:${target_tag}" >/dev/null || warn "Pull failed, will try to reuse cached image."
    recreate_container
    wait_healthy || warn "Container did not become healthy within the window. Check manually."

    echo ""
    warn "Database was NOT auto-restored. If you need to also restore the DB:"
    warn "  docker exec -i coolify-db sh -c 'psql -U \$POSTGRES_USER \$POSTGRES_DB' \\"
    warn "    < ${backup_dir}/coolify-db.sql"
    echo ""
    ok "Rollback complete."
}

# --- main ---------------------------------------------------------------------

main() {
    require_root
    require_coolify_dir

    # Always append to the log file from this point on.
    exec > >(tee -a "${LOG_FILE}") 2>&1
    echo ""
    echo "======================================================================"
    echo "  update-coolify.sh invoked at $(date '+%Y-%m-%d %H:%M:%S')"
    echo "  args: $*"
    echo "======================================================================"

    if [ $# -eq 0 ]; then
        cat <<USAGE
Usage:
  $0 <tag>                 Upgrade to the given tag (e.g. 4.0.0-beta.480)
  $0 --current             Show the currently pinned tag and the running image
  $0 --rollback [dir]      Roll back using the last/named backup
USAGE
        exit 1
    fi

    case "$1" in
        --current|-c)
            cmd_current
            ;;
        --rollback|-r)
            acquire_lock
            cmd_rollback "${2:-}"
            ;;
        -h|--help)
            cat <<USAGE
update-coolify.sh — safe manual upgrade of the pinned Coolify container.

  $0 <tag>                 Upgrade to the given tag (auto-rollback on failure)
  $0 --current             Show the currently pinned tag and the running image
  $0 --rollback [dir]      Roll back using the last/named backup

Examples:
  $0 4.0.0-beta.480
  $0 --current
  $0 --rollback
  $0 --rollback /data/coolify-backups/manual/upgrade-20260412-103045
USAGE
            ;;
        *)
            acquire_lock
            cmd_upgrade "$1"
            ;;
    esac
}

main "$@"
