// RX Power dengan kondisi GOOD/WARNING/CRITICAL
let output = "N/A";
let rx = null;

const zte = declare("InternetGatewayDevice.WANDevice.*.X_ZTE-COM_WANPONInterfaceConfig.RXPower", { value: Date.now() });
const huawei = declare("InternetGatewayDevice.WANDevice.*.X_GponInterafceConfig.RXPower", { value: Date.now() });
const fiberhome = declare("InternetGatewayDevice.WANDevice.*.X_FH_GponInterfaceConfig.RXPower", { value: Date.now() });
const ztecmcc = declare("InternetGatewayDevice.WANDevice.*.X_CMCC_EponInterfaceConfig.RXPower", { value: Date.now() });
const ztecmcg = declare("InternetGatewayDevice.WANDevice.*.X_CMCC_GponInterfaceConfig.RXPower", { value: Date.now() });
const gm220se = declare("InternetGatewayDevice.WANDevice.*.X_CT-COM_EponInterfaceConfig.RXPower", { value: Date.now() });
const gm220sg = declare("InternetGatewayDevice.WANDevice.*.X_CT-COM_GponInterfaceConfig.RXPower", { value: Date.now() });
const f477v2 = declare("InternetGatewayDevice.WANDevice.*.X_CU_WANEPONInterfaceConfig.OpticalTransceiver.RXPower", { value: Date.now() });
const nokia = declare("InternetGatewayDevice.X_ALU_OntOpticalParam.RXPower", { value: Date.now() });

function toNumber(v) {
  const n = Number(v);
  return Number.isFinite(n) ? n : null;
}

function convertRawRx(v) {
  const n = toNumber(v);
  if (n === null) return null;
  if (n < 0) return n;
  if (n > 0) return 30 + (Math.log10(n * Math.pow(10, -7)) * 10);
  return null;
}

function firstConverted(node) {
  if (!node || !node.size) return null;
  const n = convertRawRx(node.value[0]);
  return n === null ? null : Math.round(n * 100) / 100;
}

function firstNumeric(node) {
  if (!node || !node.size) return null;
  for (let p of node) {
    const n = toNumber(p.value[0]);
    if (n !== null) return Math.round(n * 100) / 100;
  }
  return null;
}

if (rx === null) rx = firstConverted(zte);
if (rx === null) rx = firstConverted(ztecmcc);
if (rx === null) rx = firstConverted(ztecmcg);
if (rx === null) rx = firstConverted(gm220se);
if (rx === null) rx = firstConverted(gm220sg);
if (rx === null) rx = firstConverted(f477v2);
if (rx === null) rx = firstNumeric(huawei);
if (rx === null) rx = firstNumeric(fiberhome);
if (rx === null) rx = firstNumeric(nokia);

if (rx !== null) {
  let status = "CRITICAL";
  if (rx >= -27) {
    status = "GOOD";
  } else if (rx >= -30) {
    status = "WARNING";
  }
  output = rx + " dBm (" + status + ")";
}

return { writable: false, value: [output, "xsd:string"] };
