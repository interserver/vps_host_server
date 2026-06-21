#!/usr/bin/env bash
# dbupdate.sh <app> [<app> ...]
# Updates vps_templates.template_config from cloudinit/<app>.yaml for ALL rows
# whose template_file matches that app (both template_type 14 & 16, any base).
set -uo pipefail
CIDIR=/home/sites/vps_host_server/cloudinit
DBH=174.138.179.250; DBU=datacentered
DBP=$(grep -A2 datacentered /home/sites/mystage/include/config/config.db.php | grep db_pass | head -1 | sed "s/.*=> '\(.*\)'.*/\1/")
MYSQL=(mysql -h "$DBH" -u "$DBU" -p"$DBP" my)
for app in "$@"; do
  f="$CIDIR/$app.yaml"
  [ -f "$f" ] || { echo "$app: NO YAML at $f"; continue; }
  python3 -c "import yaml;yaml.safe_load(open('$f'))" || { echo "$app: YAML INVALID, skipping"; continue; }
  rows=$("${MYSQL[@]}" -N -B -e "SELECT COUNT(*) FROM vps_templates WHERE template_file LIKE 'cloud-init:%:$app.yaml';")
  hex=$(xxd -p "$f" | tr -d '\n')
  "${MYSQL[@]}" -e "UPDATE vps_templates SET template_config=CONVERT(UNHEX('$hex') USING utf8mb4) WHERE template_file LIKE 'cloud-init:%:$app.yaml';"
  # verify
  vlen=$("${MYSQL[@]}" -N -B -e "SELECT LENGTH(template_config) FROM vps_templates WHERE template_file LIKE 'cloud-init:%:$app.yaml' LIMIT 1;")
  flen=$(wc -c < "$f")
  echo "$app: updated $rows row(s); db_len=$vlen file_len=$flen $([ "$vlen" = "$flen" ] && echo OK || echo 'LEN-DIFF(check utf8)')"
done
