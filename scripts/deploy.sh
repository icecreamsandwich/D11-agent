#!/usr/bin/env bash
# =============================================================================
# Deployment script for D11 Multilingual platform.
#
# Usage:
#   ./scripts/deploy.sh [environment]
#
# Environments: development | staging | production (default: production)
# =============================================================================

set -euo pipefail

ENVIRONMENT="${1:-production}"
COMPOSER="composer"

# Resolve the project root (one level above this script).
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

# Detect execution context: inside DDEV container vs host machine.
# On the host, all drush/composer calls must go through `ddev` so they
# run inside the container where the database is reachable.
# File paths passed to drush must also use the container-side path
# (/var/www/html) rather than the host absolute path.
if [[ -n "${IS_DDEV_PROJECT:-}" ]] || [[ -f /.dockerenv ]]; then
  # Already inside the container (CI or ddev ssh).
  DRUSH="./vendor/bin/drush"
  CONTAINER_PROJECT_ROOT="$PROJECT_ROOT"
else
  # Host machine with DDEV — proxy through ddev exec.
  DRUSH="ddev drush"
  COMPOSER="ddev composer"
  # DDEV always mounts the project root at /var/www/html inside the container.
  CONTAINER_PROJECT_ROOT="/var/www/html"
fi

echo "======================================================"
echo " D11 Multilingual Deploy — environment: $ENVIRONMENT"
echo "======================================================"

# 1. Pull latest code (skip in local dev).
if [[ "$ENVIRONMENT" != "development" ]]; then
  echo "[1/9] Pulling latest code..."
  git pull origin main
else
  echo "[1/9] Skipping git pull (development environment)."
fi

# 2. Install/update Composer dependencies.
echo "[2/9] Installing Composer dependencies..."
if [[ "$ENVIRONMENT" == "production" ]]; then
  $COMPOSER install --no-dev --optimize-autoloader --no-interaction
else
  $COMPOSER install --optimize-autoloader --no-interaction
fi

# 3. Enable maintenance mode.
echo "[3/9] Enabling maintenance mode..."
$DRUSH state:set system.maintenance_mode 1 --input-format=integer
$DRUSH cache:rebuild

# 4. Run database updates.
echo "[4/9] Running database updates..."
$DRUSH updatedb --yes

# 5. Import configuration (config_split handles environment splits).
echo "[5/9] Importing configuration..."
$DRUSH config:import --yes

# 6. Import custom .po translation files for each language.
echo "[6/9] Importing translation files..."
# HOST_TRANSLATIONS_DIR  — used to glob .po files on the host filesystem.
# DRUSH_TRANSLATIONS_DIR — path as seen by drush inside the container.
HOST_TRANSLATIONS_DIR="$PROJECT_ROOT/translations/custom"
DRUSH_TRANSLATIONS_DIR="$CONTAINER_PROJECT_ROOT/translations/custom"

if [[ -d "$HOST_TRANSLATIONS_DIR" ]]; then
  for po_file in "$HOST_TRANSLATIONS_DIR"/*.po; do
    langcode=$(basename "$po_file" .po)
    # Build the container-side path for this .po file.
    container_po="$DRUSH_TRANSLATIONS_DIR/$(basename "$po_file")"
    echo "  → Importing $langcode.po (container path: $container_po)..."
    $DRUSH locale:import "$langcode" "$container_po" \
      --type=customized \
      --override=all
  done
else
  echo "  No translations directory found at $HOST_TRANSLATIONS_DIR — skipping."
fi

# 7. Rebuild caches (post config import).
echo "[7/9] Rebuilding caches..."
$DRUSH cache:rebuild

# 8. Warm up caches for primary language paths (optional — comment out if slow).
if [[ "$ENVIRONMENT" == "production" ]]; then
  echo "[8/9] Warming up cache for primary language paths..."
  SITE_URL=${SITE_URL:-"https://example.com"}
  for lang in en fr de ar; do
    curl -s -o /dev/null -w "  /%-3s → HTTP %{http_code}\n" \
      "$SITE_URL/$lang" || true
  done
else
  echo "[8/9] Skipping cache warm-up (non-production)."
fi

# 9. Disable maintenance mode.
echo "[9/9] Disabling maintenance mode..."
$DRUSH state:set system.maintenance_mode 0 --input-format=integer

echo ""
echo "======================================================"
echo " Deploy complete. Environment: $ENVIRONMENT"
echo "======================================================"
