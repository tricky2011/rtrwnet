#!/usr/bin/env bash
set -euo pipefail

NBI_URL="${1:-http://127.0.0.1:7557}"
LIMIT="${2:-500}"

payload='{"name":"getParameterValues","parameterNames":["RXPower","WlanPassword","pppoeUsername","pppoeIP","InternetGatewayDevice.X_CT-COM_UserInfo.UserName","InternetGatewayDevice.X_CT-COM_UserInfo.Password","InternetGatewayDevice.X_CMCC_UserInfo.UserName","InternetGatewayDevice.X_CMCC_UserInfo.Password","InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID","InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase","InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.SSID","InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.PreSharedKey.1.KeyPassphrase","InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.SSID","InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.PreSharedKey.1.KeyPassphrase","InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress","InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.ExternalIPAddress","InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Username","InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Password","InternetGatewayDevice.WANDevice.1.X_CT-COM_EponInterfaceConfig.RXPower","InternetGatewayDevice.WANDevice.1.X_CT-COM_PONInterfaceConfig.RXPower","InternetGatewayDevice.WANDevice.1.X_CT-COM_WANPONInterfaceConfig.RXPower","InternetGatewayDevice.WANDevice.1.X_ZTE-COM_WANPONInterfaceConfig.RXPower","InternetGatewayDevice.WANDevice.1.WANPONInterfaceConfig.RXPower","Device.WiFi.SSID.1.SSID","Device.WiFi.AccessPoint.1.Security.KeyPassphrase","Device.PON.Interface.1.OpticalSignalLevel","Device.PON.Interface.1.RXPower"]}'

ok=0
fail=0
count=0

while IFS= read -r did; do
  [ -z "$did" ] && continue
  count=$((count+1))
  did_enc="$(python3 - <<'PY' "$did"
import sys, urllib.parse
print(urllib.parse.quote(sys.argv[1], safe=''))
PY
)"
  code="$(curl -sS -o /tmp/genieacs_refresh_${count}.out -w '%{http_code}' \
    -X POST "${NBI_URL}/devices/${did_enc}/tasks?connection_request" \
    -H 'Content-Type: application/json' \
    --data "$payload" || true)"
  if [ "$code" = "200" ] || [ "$code" = "202" ]; then
    ok=$((ok+1))
  else
    fail=$((fail+1))
  fi
  echo "[$count] $did -> HTTP $code"
done < <(curl -sS "${NBI_URL}/devices?limit=${LIMIT}" | jq -r '.[]._id')

echo "Done. total=$count ok=$ok fail=$fail"
