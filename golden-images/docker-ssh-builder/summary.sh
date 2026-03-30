#!/bin/bash
#
# Summary display script - shows real-time build status
#

LOG_DIR="/workspace/docker-ssh-builder/logs"
OUTPUT_DIR="/workspace/docker-ssh-builder/output"

# Color codes
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

# Read results if available
GOOD_TEMPLATES=()
BAD_TEMPLATES=()

if [[ -f "$OUTPUT_DIR/good_templates.txt" ]]; then
    while IFS= read -r line; do
        GOOD_TEMPLATES+=("$line")
    done < "$OUTPUT_DIR/good_templates.txt"
fi

if [[ -f "$OUTPUT_DIR/bad_templates.txt" ]]; then
    while IFS= read -r line; do
        BAD_TEMPLATES+=("$line")
    done < "$OUTPUT_DIR/bad_templates.txt"
fi

# Count log entries
TOTAL_LOGS=$(find "$LOG_DIR" -name "*.log" 2>/dev/null | wc -l)
IN_PROGRESS=$(grep -l "Processing" "$LOG_DIR"/*.log 2>/dev/null | wc -l)
BUILDING=$(grep -l "Building" "$LOG_DIR"/*.log 2>/dev/null | wc -l)

# Calculate stats
TOTAL_SUCCESS=${#GOOD_TEMPLATES[@]}
TOTAL_FAILED=${#BAD_TEMPLATES[@]}
TOTAL_PROCESSED=$((TOTAL_SUCCESS + TOTAL_FAILED))

# Show summary
echo ""
echo "=============================================="
echo -e "${CYAN}DOCKER SSH IMAGE BUILDER - LIVE SUMMARY${NC}"
echo "=============================================="
echo ""
echo "Time: $(date '+%Y-%m-%d %H:%M:%S')"
echo ""
echo "Total Logs: $TOTAL_LOGS"
echo "Currently Building: $BUILDING"
echo ""
echo -e "${GREEN}Success: $TOTAL_SUCCESS${NC}"
echo -e "${RED}Failed: $TOTAL_FAILED${NC}"
echo -e "Total Processed: $TOTAL_PROCESSED"
echo ""

if [[ $TOTAL_PROCESSED -gt 0 ]]; then
    success_rate=$((TOTAL_SUCCESS * 100 / TOTAL_PROCESSED))
    echo -e "Success Rate: ${GREEN}${success_rate}%${NC}"
fi

echo ""
echo "----------------------------------------------"
echo -e "${GREEN}SUCCESSFUL BUILDS (${#GOOD_TEMPLATES[@]})${NC}"
echo "----------------------------------------------"
for template in "${GOOD_TEMPLATES[@]}"; do
    echo "✓ $template"
done

echo ""
echo "----------------------------------------------"
echo -e "${RED}FAILED BUILDS (${#BAD_TEMPLATES[@]})${NC}"
echo "----------------------------------------------"
for template in "${BAD_TEMPLATES[@]}"; do
    echo "✗ $template"
done

echo ""
echo "=============================================="
echo "Recent Log Entries:"
echo "=============================================="
tail -20 "$LOG_DIR/build.log" 2>/dev/null | tail -10

echo ""
echo "Press Ctrl+C to exit"
