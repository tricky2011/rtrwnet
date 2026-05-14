// Mendapatkan informasi PON
let pon = "";
if (args[1].value) {
  pon = args[1].value[0];
  declare("InternetGatewayDevice.DeviceInfo.X_HW_UpPortMode", {value: Date.now()});
  
}
else {
  
  let v1 = declare("InternetGatewayDevice.WANDevice.*.WANCommonInterfaceConfig.WANAccessType", {value: Date.now()});

  if (v1.size) {
    pon = v1.value[0];
  }
}

pon = pon.toUpperCase();

const brand = declare('DeviceID.Manufacturer', {value: Date.now(86400000)}).value[0];
if (brand === 'RAISECOM' || brand === 'Raisecom' ) {
  pon = "GPON";
} else if (pon.includes("EPON")) {
  pon = "EPON";
} else if (pon.includes("GPON")) {
  pon = "GPON";
} else if (pon.includes("PON")) {
  pon = "GPON";
} else {
  pon = "Ethernet";
}


return {writable: false, value: [pon, "xsd:string"]};
