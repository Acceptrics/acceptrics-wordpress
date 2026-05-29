#!/bin/bash
set -e

cd "$(dirname "$0")"

OUT="acceptrics-consent-banner.zip"
rm -f "$OUT"

zip -r "$OUT" acceptrics-consent-banner \
    -x "*.DS_Store" \
    -x "acceptrics-consent-banner/tests/*" \
    -x "acceptrics-consent-banner/test-relay.sh" \
    -x "acceptrics-consent-banner/buildPlugin.sh" \
    -x "acceptrics-consent-banner/*.zip" \
    -x "acceptrics-consent-banner/acceptrics-consent-banner/*" \
    -x "*/.env" \
    -x "*/.env.*"

echo "Built $OUT"
zip -sf "$OUT" | head -40
