// WLAN PW
let m = "";
if (args[1].value) {
  m = args[1].value[0];
  declare("InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase", null, {value: m});
  declare("InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase", null, {value: m});
}
else {
  let v1 = declare("InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase", {value: Date.now()});
  let v2 = declare("InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase", {value: Date.now()});


  if (v1.size) {
    m = v1.value[0];
  }
  else if (v2.size) {
    m = v2.value[0];
  }
}

return {writable: true, value: [m, "xsd:string"]};
