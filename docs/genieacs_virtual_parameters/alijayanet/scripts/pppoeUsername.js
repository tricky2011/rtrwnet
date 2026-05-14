// PPPoE Username
let user = "";
if (args[1].value) {
  user = args[1].value[0];
  declare("InternetGatewayDevice.WANDevice.*.WANConnectionDevice.*.WANPPPConnection.*.Username", null, {value: user});
} else {
  let v1 = declare("InternetGatewayDevice.WANDevice.1.WANConnectionDevice.*.WANPPPConnection.1.Username", {value: Date.now()});
  let v2 = declare("InternetGatewayDevice.WANDevice.1.WANConnectionDevice.*.WANPPPConnection.2.Username", {value: Date.now()});
  let v3 = declare("InternetGatewayDevice.WANDevice.1.WANConnectionDevice.*.WANPPPConnection.3.Username", {value: Date.now()});
  let v4 = declare("InternetGatewayDevice.WANDevice.1.WANConnectionDevice.*.WANPPPConnection.4.Username", {value: Date.now()});
  let v5 = declare("InternetGatewayDevice.WANDevice.1.WANConnectionDevice.*.WANPPPConnection.5.Username", {value: Date.now()});
  let v6 = declare("InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANPPPConnection.1.Username", {value: Date.now()});
  let v7 = declare("InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANPPPConnection.2.Username", {value: Date.now()});
  let v8 = declare("InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANPPPConnection.3.Username", {value: Date.now()});
  let v9 = declare("InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANPPPConnection.4.Username", {value: Date.now()});
  let v10 = declare("InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANPPPConnection.5.Username", {value: Date.now()});

  if (v1.size && v1.value[0]) {
    user = v1.value[0];
  } else if (v2.size && v2.value[0]) {
    user = v2.value[0];
  } else if (v3.size && v3.value[0]) {
    user = v3.value[0];
  } else if (v4.size && v4.value[0]) {
    user = v4.value[0];
  } else if (v5.size && v5.value[0]) {
    user = v5.value[0];
  } else if (v6.size && v6.value[0]) {
    user = v6.value[0];
  } else if (v7.size && v7.value[0]) {
    user = v7.value[0];
  } else if (v8.size && v8.value[0]) {
    user = v8.value[0];
  } else if (v9.size && v9.value[0]) {
    user = v9.value[0];
  } else if (v10.size && v10.value[0]) {
    user = v10.value[0];
  }
}


return {writable: true, value: [user, "xsd:string"]};
