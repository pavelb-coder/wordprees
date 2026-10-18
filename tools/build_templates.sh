#!/usr/bin/env bash
# Build the single XLSX template and its guide from the shared import schema.
# CSV/JSON/XLSM remain user import formats; no such sample files are distributed.
# Usage: WP_PATH=/path/to/test-wordpress tools/build_templates.sh
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(dirname "$SCRIPT_DIR")"
WP_PATH="${WP_PATH:-/home/user/wp-test}"

echo "→ Dumping schema from WPOlympiad\\ImportTemplate (WP: $WP_PATH)"
wp --allow-root --path="$WP_PATH" eval-file "$SCRIPT_DIR/dump_import_schema.php"

echo "→ Writing README_IMPORT.txt (no CSV/JSON sample files)"
wp --allow-root --path="$WP_PATH" eval '
$dir = "'"$PLUGIN_DIR"'/templates/";
file_put_contents($dir . "README_IMPORT.txt", WPOlympiad\NetworkImport::get_template_readme());
echo "   README: " . filesize($dir . "README_IMPORT.txt") . " B\n";
'

if ! python3 -c "import xlsxwriter" 2>/dev/null; then
    echo "Install the development dependency first: python3 -m pip install xlsxwriter" >&2
    exit 1
fi
python3 "$SCRIPT_DIR/generate_excel_templates.py"
echo "✓ Only questions_template.xlsx and README_IMPORT.txt are built; no network folder is modified."
