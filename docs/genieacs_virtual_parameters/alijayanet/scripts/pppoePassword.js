// PPPoE Password
let pw = "";
if (args[1].value) {
  pw = args[1].value[0];
  declare("InternetGatewayDevice.WANDevice.*.WANConnectionDevice.*.WANPPPConnection.*.Password", null, {value: pw});
}
else {
  let v1 = declare("InternetGatewayDevice.WANDevice.*.WANConnectionDevice.*.WANPPPConnection.*.Password", {value: Date.now()});

  if (v1.size) {
    pw = v1.value[0];
  }
}

return {writable: true, value: [pw, "xsd:string"]};
