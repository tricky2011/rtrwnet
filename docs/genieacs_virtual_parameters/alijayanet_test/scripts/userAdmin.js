// virtual parameter user admin
let m = "";
if (args[1].value) {
  m = args[1].value[0];
  declare("InternetGatewayDevice.X_CU_Function.Web.UserName", null, {value: m});
  declare("InternetGatewayDevice.UserInterface.X_ZTE-COM_WebUserInfo.UserName", null, {value: m});
  declare("InternetGatewayDevice.UserInterface.X_HW_WebUserInfo.1.UserName", null, {value: m});
  declare("InternetGatewayDevice.DeviceInfo.X_FH_Account.X_FH_WebUserInfo.WebUsername", null, {value: m});
  declare("InternetGatewayDevice.User.2.Username", null, {value: m});
  declare("InternetGatewayDevice.X_ZTE-COM_UserInterface.X_ZTE-COM_WebUserInfo.UserName", null, {value: m});

}
else {
  let v1 = declare("InternetGatewayDevice.X_CU_Function.Web.UserName", {value: Date.now()});
  let v2 = declare("InternetGatewayDevice.UserInterface.X_ZTE-COM_WebUserInfo.UserName", {value: Date.now()});
  let v3 = declare("InternetGatewayDevice.UserInterface.X_HW_WebUserInfo.1.UserName", {value: Date.now()});
  let v4 = declare("InternetGatewayDevice.DeviceInfo.X_FH_Account.X_FH_WebUserInfo.WebUsername", {value: Date.now()});
  let v5 = declare("InternetGatewayDevice.User.2.Username", {value: Date.now()});
  let v6 = declare("InternetGatewayDevice.X_ZTE-COM_UserInterface.X_ZTE-COM_WebUserInfo.UserName", {value: Date.now()});

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
