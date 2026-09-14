#!/usr/bin/env bash
# Buduje paczkę SQLMON. Numer wersji czytany jest z APP_VERSION w sqlmon.php,
# więc nazwa archiwum nigdy nie rozjedzie się z zawartością.
set -euo pipefail
cd "$(dirname "$0")"

WERSJA=$(grep -oP "define\('APP_VERSION',\s*'\K[0-9]+\.[0-9]+\.[0-9]+" sqlmon.php)
if [ -z "$WERSJA" ]; then echo "Nie znaleziono APP_VERSION w sqlmon.php" >&2; exit 1; fi

ARCHIWUM="sqlmon-v${WERSJA}.zip"
rm -f "$ARCHIWUM"
zip -q "$ARCHIWUM" sqlmon.php 08_kolektor_historii.sql CHANGELOG.md zbuduj_paczke.sh
echo "Zbudowano: $ARCHIWUM (wersja $WERSJA)"
