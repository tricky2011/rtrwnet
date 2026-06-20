// virtual parameter user password
let m = "admin";
if (args[1].value && String(args[1].value[0] || '').trim() !== '') {
  m = args[1].value[0];
  declare("InternetGatewayDevice.X_CU_Function.Web.UserPassword", null, {value: m});
  declare("InternetGatewayDevice.UserInterface.X_ZTE-COM_WebUserInfo.UserPassword", null, {value: m});
  declare("InternetGatewayDevice.UserInterface.X_HW_WebUserInfo.1.Password", null, {value: m});
  declare("InternetGatewayDevice.DeviceInfo.X_FH_Account.X_FH_WebUserInfo.WebPassword", null, {value: m});
  declare("InternetGatewayDevice.User.2.Password", null, {value: m});
  declare("InternetGatewayDevice.X_ZTE-COM_UserInterface.X_ZTE-COM_WebUserInfo.UserPassword", null, {value: m});
}
else {
  let v1 = declare("InternetGatewayDevice.X_CU_Function.Web.UserPassword", {value: Date.now()});
  let v2 = declare("InternetGatewayDevice.UserInterface.X_ZTE-COM_WebUserInfo.UserPassword", {value: Date.now()});
  let v3 = declare("InternetGatewayDevice.UserInterface.X_HW_WebUserInfo.1.Password", {value: Date.now()});
  let v4 = declare("InternetGatewayDevice.DeviceInfo.X_FH_Account.X_FH_WebUserInfo.WebPassword", {value: Date.now()});
  let v5 = declare("InternetGatewayDevice.User.2.Password", {value: Date.now()});
  let v6 = declare("InternetGatewayDevice.X_ZTE-COM_UserInterface.X_ZTE-COM_WebUserInfo.UserPassword", {value: Date.now()});

  if (v1.size) {
    m = v1.value[0];
  }
  else if (v2.size) {
    m = v2.value[0];
  }
  else if (v3.size) {
    m = v3.value[0];
  }
  else if (v4.size) {
    m = v4.value[0];
  }
  else if (v5.size) {
    m = v5.value[0];
  }
  else if (v6.size) {
    m = v6.value[0];
  }
}

return {writable: true, value: [m, "xsd:string"]};
