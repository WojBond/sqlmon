#!/usr/bin/env bash
# Buduje paczkę SQLMON. Numer wersji czytany jest z APP_VERSION w wspolne.php,
# więc nazwa archiwum nigdy nie rozjedzie się z zawartością.
set -euo pipefail
cd "$(dirname "$0")"

WERSJA=$(grep -oP "define\('APP_VERSION',\s*'\K[0-9]+\.[0-9]+\.[0-9]+" wspolne.php)
if [ -z "$WERSJA" ]; then echo "Nie znaleziono APP_VERSION w wspolne.php" >&2; exit 1; fi

ARCHIWUM="sqlmon-v${WERSJA}.zip"
rm -f "$ARCHIWUM"
INSTRUKCJA="SQLMON-instrukcja-obslugi-v${WERSJA}.pdf"
zip -q "$ARCHIWUM" index.php sqlmon.php konfiguracja.php wspolne.php *.sql README.md CHANGELOG.md zbuduj_paczke.sh
if [ -f "$INSTRUKCJA" ]; then
    zip -q "$ARCHIWUM" "$INSTRUKCJA"
else
    echo "Uwaga: brak $INSTRUKCJA — paczka bez instrukcji. Uruchom najpierw: python3 instrukcja.py" >&2
fi
echo "Zbudowano: $ARCHIWUM (wersja $WERSJA)"
