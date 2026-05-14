// virtual parameter superadmin password
let m = "";
if (args[1].value) {
  m = args[1].value[0];
  declare("InternetGatewayDevice.X_CU_Function.Web.AdminPassword", null, {value: m});
  declare("InternetGatewayDevice.UserInterface.X_ZTE-COM_WebUserInfo.AdminPassword", null, {value: m});
  declare("InternetGatewayDevice.UserInterface.X_HW_WebUserInfo.2.Password", null, {value: m});
  declare("InternetGatewayDevice.DeviceInfo.X_CMCC_TeleComAccount.Password", null, {value: m});
  declare("InternetGatewayDevice.DeviceInfo.X_FH_Account.X_FH_WebUserInfo.WebSuperPassword", null, {value: m});
  declare("InternetGatewayDevice.User.1.Password", null, {value: m});
  declare("InternetGatewayDevice.X_Authentication.WebAccount.Password", null, {value: m});
  declare("InternetGatewayDevice.DeviceInfo.X_CT-COM_TeleComAccount.Password", null, {value: m});
  declare("InternetGatewayDevice.X_ZTE-COM_UserInterface.X_ZTE-COM_WebUserInfo.AdminPassword", null, {value: m});
}
else {
  let v1 = declare("InternetGatewayDevice.X_CU_Function.Web.AdminPassword", {value: Date.now()});
  let v2 = declare("InternetGatewayDevice.X_Authentication.WebAccount.Password", {value: Date.now()});
  let v3 = declare("InternetGatewayDevice.UserInterface.X_HW_WebUserInfo.2.Password", {value: Date.now()});
  let v4 = declare("InternetGatewayDevice.DeviceInfo.X_CT-COM_TeleComAccount.Password", {value: Date.now()});
  let v5 = declare("InternetGatewayDevice.DeviceInfo.X_CMCC_TeleComAccount.Password", {value: Date.now()});
  let v6 = declare("InternetGatewayDevice.DeviceInfo.X_FH_Account.X_FH_WebUserInfo.WebSuperPassword", {value: Date.now()});
  let v7 = declare("InternetGatewayDevice.User.1.Password", {value: Date.now()});
  let v8 = declare("InternetGatewayDevice.UserInterface.X_ZTE-COM_WebUserInfo.AdminPassword", {value: Date.now()});
  let v9 = declare("InternetGatewayDevice.X_ZTE-COM_UserInterface.X_ZTE-COM_WebUserInfo.AdminPassword", {value: Date.now()});

	
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
  else if (v7.size) {
    m = v7.value[0];
  }
  else if (v8.size) {
    m = v8.value[0];
  }
  else if (v9.size) {
    m = v9.value[0];
  }
}

return {writable: true, value: [m, "xsd:string"]};
