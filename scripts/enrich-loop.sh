#!/bin/bash
#
# Enrich Loop — continuous free-source restaurant data seeding
#
# Cycles through all configured cities × cuisines using only free APIs
# (BizData, Overpass, Socrata) — no SerpApi quota consumed. Runs the
# enrichment pipeline in a continuous loop to build nationwide coverage.
#
# Usage:
#   ./scripts/enrich-loop.sh                    # Run with defaults (1h interval, 30m timeout)
#   ./scripts/enrich-loop.sh 1800               # Run with 30-minute interval
#   ./scripts/enrich-loop.sh --interval=3600    # Explicit interval
#   ./scripts/enrich-loop.sh --once             # Single pass, no loop
#   ./scripts/enrich-loop.sh --timeout=600      # 10 min timeout per enrich command
#
# Config (env overrides):
#   ENRICH_INTERVAL    Seconds between full cycles (default: 3600)
#   ENRICH_TIMEOUT     Max seconds for each enrichment command (default: 1800)
#   ENRICH_CUISINES    Comma-separated cuisine slugs (default: all)
#

set -e
set -o pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
LOG_DIR="$PROJECT_DIR/storage/logs"
TIMESTAMP=$(date '+%Y%m%d_%H%M%S')

INTERVAL="${ENRICH_INTERVAL:-3600}"
TIMEOUT="${ENRICH_TIMEOUT:-1800}"
ONCE=false
DRY_RUN=false
CUISINE_FLAG=""

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

mkdir -p "$LOG_DIR"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --once)
            ONCE=true
            shift
            ;;
        --dry-run)
            DRY_RUN=true
            shift
            ;;
        --interval=*)
            INTERVAL="${1#*=}"
            shift
            ;;
        --timeout=*)
            TIMEOUT="${1#*=}"
            shift
            ;;
        --cuisine=*)
            CUISINE_FLAG="--cuisine=${1#*=}"
            shift
            ;;
        -h|--help)
            echo "Enrich Loop — continuous free-source restaurant data seeding"
            echo ""
            echo "Usage:"
            echo "  ./scripts/enrich-loop.sh                    # Default interval (3600s)"
            echo "  ./scripts/enrich-loop.sh 1800               # Custom interval"
            echo "  ./scripts/enrich-loop.sh --interval=3600    # Explicit interval"
            echo "  ./scripts/enrich-loop.sh --once             # Single pass, no loop"
            echo "  ./scripts/enrich-loop.sh --dry-run          # Show what would run, don't execute"
            echo "  ./scripts/enrich-loop.sh --timeout=600      # Kill stuck enrich after 10 min"
            echo "  ./scripts/enrich-loop.sh --cuisine=thai     # Single cuisine"
            echo ""
            echo "Env overrides:"
            echo "  ENRICH_INTERVAL    Seconds between passes (default: 3600)"
            echo "  ENRICH_TIMEOUT     Max seconds per enrich command (default: 1800)"
            exit 0
            ;;
        [0-9]*)
            INTERVAL="$1"
            shift
            ;;
        *)
            echo -e "${RED}Unknown argument: $1${NC}"
            exit 1
            ;;
    esac
done

validate_interval() {
    if [ "$ONCE" = false ] && [ "$INTERVAL" -lt 60 ] 2>/dev/null; then
        echo -e "${YELLOW}Warning: interval ${INTERVAL}s is very short. Minimum recommended is 300s (5 min).${NC}"
    fi
}

validate_interval

cd "$PROJECT_DIR"

SESSION_LOG="$LOG_DIR/enrich_loop_${TIMESTAMP}.log"
touch "$SESSION_LOG"

CYCLE=0
PASS=0

cleanup() {
    echo ""
    echo -e "${BLUE}[$(date '+%Y-%m-%d %H:%M:%S')] Enrich loop shutting down...${NC}"
    echo -e "${BLUE}Session log: $SESSION_LOG${NC}"
    exit 0
}
trap cleanup INT TERM HUP

echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}      ENRICH LOOP STARTING                       ${NC}"
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}Mode:${NC}      $([ "$DRY_RUN" = true ] && echo 'dry-run' || ([ "$ONCE" = true ] && echo 'single pass' || echo "continuous (${INTERVAL}s interval)"))"
echo -e "${BLUE}Timeout:${NC}   ${TIMEOUT}s per enrich command"
echo -e "${BLUE}Cuisines:${NC}  ${CUISINE_FLAG:-all}"
echo -e "${BLUE}Log:${NC}       $SESSION_LOG"
echo ""

while true; do
    CYCLE=$((CYCLE + 1))
    CYCLE_START=$(date +%s)

    echo ""
    echo -e "${CYAN}══════════════════ CYCLE $CYCLE ══════════════════${NC}"
    echo -e "${BLUE}[$(date '+%Y-%m-%d %H:%M:%S')] Starting enrichment pass${NC}"
    echo ""

    # Phase 1: Free-source enrichment (all cities, no SerpApi)
    ENRICH_CMD="timeout $TIMEOUT php artisan restaurants:enrich --all-cities --free-only $CUISINE_FLAG"
    echo -e "${YELLOW}[Phase 1] Free-source enrichment (all cities, no SerpApi, ${TIMEOUT}s timeout)...${NC}"
    echo -e "${BLUE}  \$ ${ENRICH_CMD}${NC}"
    if [ "$DRY_RUN" = false ]; then
        if $ENRICH_CMD 2>&1 | tee -a "$SESSION_LOG"; then
            echo -e "${GREEN}[Phase 1] Enrichment complete${NC}"
        else
            echo -e "${RED}[Phase 1] Enrichment encountered errors or timed out (continuing)${NC}"
        fi
    else
        echo -e "${YELLOW}  (dry-run — skipped)${NC}"
    fi

    # Phase 2: Re-score all restaurants
    SCORE_CMD="timeout $TIMEOUT php artisan restaurants:score"
    echo ""
    echo -e "${YELLOW}[Phase 2] Re-scoring all restaurants${NC}"
    echo -e "${BLUE}  \$ ${SCORE_CMD}${NC}"
    if [ "$DRY_RUN" = false ]; then
        if $SCORE_CMD 2>&1 | tee -a "$SESSION_LOG"; then
            echo -e "${GREEN}[Phase 2] Scoring complete${NC}"
        else
            echo -e "${RED}[Phase 2] Scoring encountered errors or timed out (continuing)${NC}"
        fi
    else
        echo -e "${YELLOW}  (dry-run — skipped)${NC}"
    fi

    # Phase 3: Backfill city/state via reverse geocoding for any restaurants
    # that were enriched or persisted without city/state. This discovers new
    # cities from live-search results that aren't in the configured list.
    GEO_CMD="timeout $TIMEOUT php artisan restaurants:backfill-location --phase=geocode"
    echo ""
    echo -e "${YELLOW}[Phase 3] Reverse-geocoding missing city/state...${NC}"
    echo -e "${BLUE}  \$ ${GEO_CMD}${NC}"
    if [ "$DRY_RUN" = false ]; then
        if $GEO_CMD 2>&1 | tee -a "$SESSION_LOG"; then
            echo -e "${GREEN}[Phase 3] Geocode complete${NC}"
        else
            echo -e "${RED}[Phase 3] Geocode encountered errors or timed out (continuing)${NC}"
        fi
    else
        echo -e "${YELLOW}  (dry-run — skipped)${NC}"
    fi

    # Phase 4: Enrich discovered cities (cities that appeared in the DB from
    # live-search persistence but aren't in the configured cities list).
    DISCOVERED_CMD="timeout $TIMEOUT php artisan restaurants:enrich --discovered --free-only"
    echo ""
    echo -e "${YELLOW}[Phase 4] Enriching discovered cities...${NC}"
    echo -e "${BLUE}  \$ ${DISCOVERED_CMD}${NC}"
    if [ "$DRY_RUN" = false ]; then
        if $DISCOVERED_CMD 2>&1 | tee -a "$SESSION_LOG"; then
            echo -e "${GREEN}[Phase 4] Discovered-city enrichment complete${NC}"
        else
            echo -e "${RED}[Phase 4] Discovered-city enrichment encountered errors or timed out (continuing)${NC}"
        fi
    else
        echo -e "${YELLOW}  (dry-run — skipped)${NC}"
    fi

    CYCLE_END=$(date +%s)
    CYCLE_DURATION=$((CYCLE_END - CYCLE_START))
    echo ""
    echo -e "${GREEN}[$(date '+%Y-%m-%d %H:%M:%S')] Cycle $CYCLE complete (${CYCLE_DURATION}s)${NC}"

    if [ "$ONCE" = true ]; then
        echo -e "${BLUE}Single pass complete. Exiting.${NC}"
        break
    fi

    echo -e "${BLUE}Next cycle in ${INTERVAL}s...${NC}"
    sleep "$INTERVAL"
done
