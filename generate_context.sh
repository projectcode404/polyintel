#!/bin/bash
# =============================================================================
# Polyintel — Context Generator
# Hybrid project: Laravel 12 (dashboard) + Python 3.13 (collectors)
#
# Usage:
#   Dari root Laravel  : bash generate_context.sh laravel
#   Dari root Python   : bash generate_context.sh python
#   Keduanya sekaligus : bash generate_context.sh all
#
# Output:
#   polyintel_context_laravel.txt
#   polyintel_context_python.txt
#   polyintel_context_all.txt  (gabungan, untuk sesi full review)
# =============================================================================

set -e

LARAVEL_DIR="${LARAVEL_DIR:-.}"       # default: current dir (jika dijalankan dari root Laravel)
PYTHON_DIR="${PYTHON_DIR:-.}"         # default: current dir (jika dijalankan dari root Python)

# Jika project ada dalam subfolder, sesuaikan:
# LARAVEL_DIR="./laravel"
# PYTHON_DIR="./python"

# =============================================================================
# FUNCTION: Generate Laravel context
# =============================================================================
generate_laravel() {
    local OUT="polyintel_context_laravel.txt"
    echo "Generating Laravel context → $OUT"

    find "$LARAVEL_DIR" -type f \( \
        -path "*/app/Models/*" \
        -o -path "*/app/Services/*" \
        -o -path "*/app/Http/Controllers/*" \
        -o -path "*/app/Http/Requests/*" \
        -o -path "*/app/Repositories/*" \
        -o -path "*/app/DTOs/*" \
        -o -path "*/app/Jobs/*" \
        -o -path "*/app/Providers/*" \
        -o -path "*/app/Console/Commands/*" \
        -o -path "*/database/migrations/*" \
        -o -path "*/database/seeders/*" \
        -o -path "*/resources/views/*" \
        -o -path "*/routes/*.php" \
        -o -path "*/tests/Unit/*" \
        -o -path "*/tests/Feature/*" \
        -o -name "composer.json" \
    \) \
    -not -path "*/vendor/*" \
    -not -path "*/.git/*" \
    -not -path "*/__pycache__/*" \
    -not -name "*.pyc" \
    -not -name "composer.lock" \
    | sort | while read -r f; do
        echo "=== FILE: $f ==="
        cat "$f"
        echo ""
    done > "$OUT"

    local SIZE
    SIZE=$(du -sh "$OUT" | cut -f1)
    echo "✅ Laravel context: $OUT ($SIZE)"
}

# =============================================================================
# FUNCTION: Generate Python context
# =============================================================================
generate_python() {
    local OUT="polyintel_context_python.txt"
    echo "Generating Python context → $OUT"

    find "$PYTHON_DIR" -type f \( \
        -path "*/collectors/*.py" \
        -o -path "*/config/*.py" \
        -o -path "*/models/*.py" \
        -o -path "*/repositories/*.py" \
        -o -path "*/services/*.py" \
        -o -path "*/utils/*.py" \
        -o -name "scheduler.py" \
        -o -name "run_collection_pipeline.py" \
        -o -name "requirements.txt" \
        -o -name "*.sql" \
    \) \
    -not -path "*/__pycache__/*" \
    -not -name "*.pyc" \
    -not -name "*.txt" \
    | sort | while read -r f; do
        echo "=== FILE: $f ==="
        cat "$f"
        echo ""
    done > "$OUT"

    # Tambahkan requirements.txt secara eksplisit
    if [ -f "$PYTHON_DIR/requirements.txt" ]; then
        echo "=== FILE: requirements.txt ===" >> "$OUT"
        cat "$PYTHON_DIR/requirements.txt" >> "$OUT"
        echo "" >> "$OUT"
    fi

    local SIZE
    SIZE=$(du -sh "$OUT" | cut -f1)
    echo "✅ Python context: $OUT ($SIZE)"
}

# =============================================================================
# FUNCTION: Generate combined context
# =============================================================================
generate_all() {
    generate_laravel
    generate_python

    local OUT="polyintel_context_all.txt"
    echo "" > "$OUT"
    echo "# =============================================================" >> "$OUT"
    echo "# POLYINTEL — FULL PROJECT CONTEXT" >> "$OUT"
    echo "# Laravel 12 + Python 3.13" >> "$OUT"
    echo "# Generated: $(date -u '+%Y-%m-%d %H:%M UTC')" >> "$OUT"
    echo "# =============================================================" >> "$OUT"
    echo "" >> "$OUT"

    echo "# === SECTION: LARAVEL ===" >> "$OUT"
    cat polyintel_context_laravel.txt >> "$OUT"

    echo "" >> "$OUT"
    echo "# === SECTION: PYTHON ===" >> "$OUT"
    cat polyintel_context_python.txt >> "$OUT"

    local SIZE
    SIZE=$(du -sh "$OUT" | cut -f1)
    echo "✅ Combined context: $OUT ($SIZE)"
}

# =============================================================================
# MAIN
# =============================================================================
MODE="${1:-all}"

case "$MODE" in
    laravel) generate_laravel ;;
    python)  generate_python ;;
    all)     generate_all ;;
    *)
        echo "Usage: bash generate_context.sh [laravel|python|all]"
        exit 1
        ;;
esac

echo ""
echo "Done. Upload file yang relevan ke Claude sesuai task:"
echo "  - Laravel task  → polyintel_context_laravel.txt"
echo "  - Python task   → polyintel_context_python.txt"
echo "  - Full review   → polyintel_context_all.txt"
