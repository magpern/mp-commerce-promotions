#!/usr/bin/env bash
# Run bulk pricing DEV acceptance (fixture + cart + promotion subprocesses).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WP="cd /opt/biopentra/apps/wordpress && docker compose --profile tools run --rm wpcli wp"

run_eval() {
  local file="$1"
  cd /opt/biopentra/apps/wordpress
  docker compose --profile tools run --rm wpcli wp eval-file "/var/www/html/wp-content/plugins/mp-commerce-promotions/scripts/${file}"
}

echo "== bulk-pricing-fixtures =="
run_eval bulk-pricing-fixtures.php

echo "== bulk-pricing-promotion-acceptance =="
run_eval bulk-pricing-promotion-acceptance.php

echo "== bulk-pricing-cart-acceptance =="
run_eval bulk-pricing-cart-acceptance.php

echo "== bulk pricing DEV acceptance: PASS =="
