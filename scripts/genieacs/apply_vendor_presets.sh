#!/usr/bin/env bash
set -euo pipefail

NBI_URL="${1:-http://127.0.0.1:7557}"
CR_USERNAME="${GENIEACS_CONNECTION_REQUEST_USERNAME:-admin}"
CR_PASSWORD="${GENIEACS_CONNECTION_REQUEST_PASSWORD:-admin}"

tmp_dir="$(mktemp -d)"
cleanup() { rm -rf "$tmp_dir"; }
trap cleanup EXIT

js_escape() {
  local value="${1:-}"
  value="${value//\\/\\\\}"
  value="${value//\"/\\\"}"
  value="${value//$'\n'/\\n}"
  printf '%s' "$value"
}

CR_USERNAME_JS="$(js_escape "$CR_USERNAME")"
CR_PASSWORD_JS="$(js_escape "$CR_PASSWORD")"

put_provision() {
  local name="$1"
  local file="$2"
  curl -sS -X PUT "${NBI_URL}/provisions/${name}" --data-binary @"${file}" >/dev/null
  echo "Provision upserted: ${name}"
}

put_preset() {
  local name="$1"
  local file="$2"
  curl -sS -X PUT "${NBI_URL}/presets/${name}" -H 'Content-Type: application/json' --data-binary @"${file}" >/dev/null
  echo "Preset upserted: ${name}"
}

# ------------------------------
# Provision scripts
# ------------------------------
cat > "${tmp_dir}/superapps_collect_common.js" <<'JS'
const ts = Date.now();
function req(path) {
  try { declare(path, { value: ts }); } catch (e) {}
}
[
  "InternetGatewayDevice.ManagementServer.ConnectionRequestUsername",
  "InternetGatewayDevice.ManagementServer.ConnectionRequestPassword",
  "Device.ManagementServer.ConnectionRequestUsername",
  "Device.ManagementServer.ConnectionRequestPassword",
  "InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress",
  "InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.ExternalIPAddress",
  "InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Username",
  "InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Password",
  "InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANPPPConnection.1.ExternalIPAddress",
  "InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANPPPConnection.1.Username",
  "InternetGatewayDevice.WANDevice.1.WANConnectionDevice.3.WANPPPConnection.1.ExternalIPAddress",
  "InternetGatewayDevice.WANDevice.1.WANConnectionDevice.3.WANPPPConnection.1.Username",
  "InternetGatewayDevice.WANDevice.1.WANConnectionDevice.4.WANPPPConnection.1.ExternalIPAddress",
  "InternetGatewayDevice.WANDevice.1.WANConnectionDevice.4.WANPPPConnection.1.Username",
  "InternetGatewayDevice.WANDevice.1.WANConnectionDevice.5.WANPPPConnection.1.ExternalIPAddress",
  "InternetGatewayDevice.WANDevice.1.WANConnectionDevice.5.WANPPPConnection.1.Username",
  "Device.IP.Interface.1.IPv4Address.1.IPAddress",
  "Device.PPP.Interface.1.Username",
  "Device.PPP.Interface.1.Password"
].forEach(req);
JS

cat > "${tmp_dir}/superapps_connection_request_credentials.js" <<JS
const username = "${CR_USERNAME_JS}";
const password = "${CR_PASSWORD_JS}";
function set(path, value) {
  try { declare(path, null, { value: value }); } catch (e) {}
}
set("InternetGatewayDevice.ManagementServer.ConnectionRequestUsername", username);
set("InternetGatewayDevice.ManagementServer.ConnectionRequestPassword", password);
set("Device.ManagementServer.ConnectionRequestUsername", username);
set("Device.ManagementServer.ConnectionRequestPassword", password);
JS

cat > "${tmp_dir}/superapps_collect_zte.js" <<'JS'
const ts = Date.now();
function req(path) {
  try { declare(path, { value: ts }); } catch (e) {}
}
[
  "InternetGatewayDevice.X_CT-COM_UserInfo.UserName",
  "InternetGatewayDevice.X_CT-COM_UserInfo.Password",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.PreSharedKey",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.SSID",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.KeyPassphrase",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.PreSharedKey.1.KeyPassphrase",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.PreSharedKey.1.PreSharedKey",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.3.SSID",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.3.KeyPassphrase",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.3.PreSharedKey.1.KeyPassphrase",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.4.SSID",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.4.KeyPassphrase",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.4.PreSharedKey.1.KeyPassphrase",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.SSID",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.KeyPassphrase",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.PreSharedKey.1.KeyPassphrase",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.6.SSID",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.7.SSID",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.8.SSID",
  "InternetGatewayDevice.WANDevice.1.X_CT-COM_EponInterfaceConfig.RXPower",
  "InternetGatewayDevice.WANDevice.1.X_CT-COM_PONInterfaceConfig.RXPower",
  "InternetGatewayDevice.WANDevice.1.X_CT-COM_WANPONInterfaceConfig.RXPower",
  "InternetGatewayDevice.WANDevice.1.X_ZTE-COM_WANPONInterfaceConfig.RXPower",
  "Device.WiFi.SSID.1.SSID",
  "Device.WiFi.AccessPoint.1.Security.KeyPassphrase",
  "Device.PON.Interface.1.OpticalSignalLevel",
  "Device.PON.Interface.1.RXPower"
].forEach(req);
JS

cat > "${tmp_dir}/superapps_collect_fiberhome.js" <<'JS'
const ts = Date.now();
function req(path) {
  try { declare(path, { value: ts }); } catch (e) {}
}
[
  "InternetGatewayDevice.X_CMCC_UserInfo.UserName",
  "InternetGatewayDevice.X_CMCC_UserInfo.Password",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.PreSharedKey",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.SSID",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.KeyPassphrase",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.PreSharedKey.1.KeyPassphrase",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.PreSharedKey.1.PreSharedKey",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.SSID",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.KeyPassphrase",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.PreSharedKey.1.KeyPassphrase",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.3.SSID",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.4.SSID",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.6.SSID",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.7.SSID",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.8.SSID",
  "InternetGatewayDevice.WANDevice.1.WANPONInterfaceConfig.RXPower",
  "InternetGatewayDevice.WANDevice.1.X_CT-COM_WANPONInterfaceConfig.RXPower",
  "Device.WiFi.SSID.1.SSID",
  "Device.WiFi.AccessPoint.1.Security.KeyPassphrase",
  "Device.PON.Interface.1.OpticalSignalLevel",
  "Device.PON.Interface.1.RXPower"
].forEach(req);
JS

cat > "${tmp_dir}/superapps_collect_vsol.js" <<'JS'
const ts = Date.now();
function req(path) {
  try { declare(path, { value: ts }); } catch (e) {}
}
[
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.PreSharedKey",
  "InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Username",
  "InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Password",
  "InternetGatewayDevice.WANDevice.1.WANPONInterfaceConfig.RXPower",
  "Device.WiFi.SSID.1.SSID",
  "Device.WiFi.AccessPoint.1.Security.KeyPassphrase",
  "Device.PON.Interface.1.OpticalSignalLevel",
  "Device.PON.Interface.1.RXPower"
].forEach(req);
JS

cat > "${tmp_dir}/superapps_collect_zimlink.js" <<'JS'
const ts = Date.now();
function req(path) {
  try { declare(path, { value: ts }); } catch (e) {}
}
[
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase",
  "InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.PreSharedKey",
  "InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Username",
  "InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Password",
  "InternetGatewayDevice.WANDevice.1.WANPONInterfaceConfig.RXPower",
  "Device.WiFi.SSID.1.SSID",
  "Device.WiFi.AccessPoint.1.Security.KeyPassphrase",
  "Device.PON.Interface.1.OpticalSignalLevel",
  "Device.PON.Interface.1.RXPower"
].forEach(req);
JS

# ------------------------------
# Preset JSON
# ------------------------------
cat > "${tmp_dir}/preset_common.json" <<'JSON'
{
  "weight": 10,
  "precondition": "{}",
  "provisions": [["superapps_collect_common"]],
  "configurations": []
}
JSON

cat > "${tmp_dir}/preset_connection_request_credentials.json" <<'JSON'
{
  "weight": 100,
  "precondition": "{}",
  "provisions": [["superapps_connection_request_credentials"]],
  "configurations": []
}
JSON

cat > "${tmp_dir}/preset_zte.json" <<'JSON'
{
  "weight": 90,
  "precondition": "{}",
  "provisions": [["superapps_collect_zte"]],
  "configurations": []
}
JSON

cat > "${tmp_dir}/preset_fiberhome.json" <<'JSON'
{
  "weight": 90,
  "precondition": "{}",
  "provisions": [["superapps_collect_fiberhome"]],
  "configurations": []
}
JSON

cat > "${tmp_dir}/preset_vsol.json" <<'JSON'
{
  "weight": 90,
  "precondition": "{}",
  "provisions": [["superapps_collect_vsol"]],
  "configurations": []
}
JSON

cat > "${tmp_dir}/preset_zimlink.json" <<'JSON'
{
  "weight": 90,
  "precondition": "{}",
  "provisions": [["superapps_collect_zimlink"]],
  "configurations": []
}
JSON

put_provision "superapps_collect_common" "${tmp_dir}/superapps_collect_common.js"
put_provision "superapps_connection_request_credentials" "${tmp_dir}/superapps_connection_request_credentials.js"
put_provision "superapps_collect_zte" "${tmp_dir}/superapps_collect_zte.js"
put_provision "superapps_collect_fiberhome" "${tmp_dir}/superapps_collect_fiberhome.js"
put_provision "superapps_collect_vsol" "${tmp_dir}/superapps_collect_vsol.js"
put_provision "superapps_collect_zimlink" "${tmp_dir}/superapps_collect_zimlink.js"

put_preset "superapps_preset_common" "${tmp_dir}/preset_common.json"
put_preset "superapps_preset_connection_request_credentials" "${tmp_dir}/preset_connection_request_credentials.json"
put_preset "superapps_preset_zte" "${tmp_dir}/preset_zte.json"
put_preset "superapps_preset_fiberhome" "${tmp_dir}/preset_fiberhome.json"
put_preset "superapps_preset_vsol" "${tmp_dir}/preset_vsol.json"
put_preset "superapps_preset_zimlink" "${tmp_dir}/preset_zimlink.json"

# Cleanup temporary test preset/provision if exists.
curl -sS -X DELETE "${NBI_URL}/presets/superapps_test_preset" >/dev/null || true
curl -sS -X DELETE "${NBI_URL}/provisions/superapps_test_collect" >/dev/null || true

echo "Done. Vendor presets applied to ${NBI_URL}"
