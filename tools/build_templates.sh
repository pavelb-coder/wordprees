#!/usr/bin/env bash
# Rebuild every question import template from the plugin's single source of truth.
#
#   1. tools/dump_import_schema.php  — ExportTemplate columns/samples → tools/import-samples.json
#   2. tools/generate_excel_templates.py — questions_template.xlsx / .xlsm (needs xlsxwriter, oletools, ms-cfb)
#
# CSV / JSON / README_IMPORT.txt are produced by the plugin itself:
#   wp eval 'file_put_contents("templates/questions_template.csv", WPOlympiad\NetworkImport::get_template_csv());' (same for json/readme)
#
# Usage:  WP_PATH=/path/to/wordpress tools/build_templates.sh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(dirname "$SCRIPT_DIR")"
WP_PATH="${WP_PATH:-/home/user/wp-test}"

echo "→ Dumping schema from WPOlympiad\\ImportTemplate (WP: $WP_PATH)"
wp --allow-root --path="$WP_PATH" eval-file "$SCRIPT_DIR/dump_import_schema.php"

echo "→ Writing CSV / JSON / README_IMPORT.txt"
wp --allow-root --path="$WP_PATH" eval '
$dir = "'"$PLUGIN_DIR"'/templates/";
file_put_contents($dir . "questions_template.csv", WPOlympiad\NetworkImport::get_template_csv());
file_put_contents($dir . "questions_template.json", WPOlympiad\NetworkImport::get_template_json());
file_put_contents($dir . "README_IMPORT.txt", WPOlympiad\NetworkImport::get_template_readme());
echo "   CSV: " . filesize($dir . "questions_template.csv") . " B\n";
echo "   JSON: " . filesize($dir . "questions_template.json") . " B\n";
echo "   README: " . filesize($dir . "README_IMPORT.txt") . " B\n";
'

echo "→ Building Excel workbooks (.xlsx / .xlsm)"
python3 -c "import xlsxwriter, oletools, ms_cfb" 2>/dev/null || {
    echo "   installing python deps…"
    python3 -m pip install --quiet xlsxwriter oletools ms-cfb
}
python3 "$SCRIPT_DIR/generate_excel_templates.py"

echo "✓ Templates rebuilt."
