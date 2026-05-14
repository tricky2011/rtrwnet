// Total active all ssid

let active = 0;
let isNumber = (value) => {
  return typeof value === 'number' && isFinite(value);
};

const merk = declare('DeviceID.Manufacturer', {value: Date.now(86400000)}).value[0];
const tipe = declare('DeviceID.ProductClass', {value: Date.now(86400000)}).value[0];
if (merk !== "FiberHome" || tipe === "HG6243C") {
//let ssid1 = declare("InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.TotalAssociations", {value: Date.now()});
let ssid2 = declare("InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.TotalAssociations", {value: Date.now()});
let ssid3 = declare("InternetGatewayDevice.LANDevice.1.WLANConfiguration.3.TotalAssociations", {value: Date.now()});
let ssid4 = declare("InternetGatewayDevice.LANDevice.1.WLANConfiguration.4.TotalAssociations", {value: Date.now()});
let ssid5 = declare("InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.TotalAssociations", {value: Date.now()});
let ssid6 = declare("InternetGatewayDevice.LANDevice.1.WLANConfiguration.6.TotalAssociations", {value: Date.now()});
let ssid7 = declare("InternetGatewayDevice.LANDevice.1.WLANConfiguration.7.TotalAssociations", {value: Date.now()});
let ssid8 = declare("InternetGatewayDevice.LANDevice.1.WLANConfiguration.8.TotalAssociations", {value: Date.now()});
/*
if (ssid1 && ssid1.size && isNumber(ssid1.value[0])) {
  active += ssid1.value[0];
}
*/
if (ssid2 && ssid2.size && isNumber(ssid2.value[0])) {
  active += ssid2.value[0];
}

if (ssid3 && ssid3.size && isNumber(ssid3.value[0])) {
  active += ssid3.value[0];
}

if (ssid4 && ssid4.size && isNumber(ssid4.value[0])) {
  active += ssid4.value[0];
}

if (ssid5 && ssid5.size && isNumber(ssid5.value[0])) {
  active += ssid5.value[0];
}

if (ssid6 && ssid6.size && isNumber(ssid6.value[0])) {
  active += ssid6.value[0];
}

if (ssid7 && ssid7.size && isNumber(ssid7.value[0])) {
  active += ssid7.value[0];
}

if (ssid8 && ssid8.size && isNumber(ssid8.value[0])) {
  active += ssid8.value[0];
}
} else {
  //let ssid1 = declare("InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.WLAN_AssociatedDeviceNumberOfEntries", {value: Date.now()});
  let ssid2 = declare("InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.WLAN_AssociatedDeviceNumberOfEntries", {value: Date.now()});
  let ssid3 = declare("InternetGatewayDevice.LANDevice.1.WLANConfiguration.3.WLAN_AssociatedDeviceNumberOfEntries", {value: Date.now()});
  let ssid4 = declare("InternetGatewayDevice.LANDevice.1.WLANConfiguration.4.WLAN_AssociatedDeviceNumberOfEntries", {value: Date.now()});
  let ssid5 = declare("InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.WLAN_AssociatedDeviceNumberOfEntries", {value: Date.now()});
  let ssid6 = declare("InternetGatewayDevice.LANDevice.1.WLANConfiguration.6.WLAN_AssociatedDeviceNumberOfEntries", {value: Date.now()});
  let ssid7 = declare("InternetGatewayDevice.LANDevice.1.WLANConfiguration.7.WLAN_AssociatedDeviceNumberOfEntries", {value: Date.now()});
  let ssid8 = declare("InternetGatewayDevice.LANDevice.1.WLANConfiguration.8.WLAN_AssociatedDeviceNumberOfEntries", {value: Date.now()});

/*
  if (ssid1 && ssid1.size && isNumber(ssid1.value[0])) {
    active += ssid1.value[0];
  }
*/  
  if (ssid2 && ssid2.size && isNumber(ssid2.value[0])) {
    active += ssid2.value[0];
  }
  
  if (ssid3 && ssid3.size && isNumber(ssid3.value[0])) {
    active += ssid3.value[0];
  }
  
  if (ssid4 && ssid4.size && isNumber(ssid4.value[0])) {
    active += ssid4.value[0];
  }
  
  if (ssid5 && ssid5.size && isNumber(ssid5.value[0])) {
    active += ssid5.value[0];
  }
  
  if (ssid6 && ssid6.size && isNumber(ssid6.value[0])) {
    active += ssid6.value[0];
  }
  
  if (ssid7 && ssid7.size && isNumber(ssid7.value[0])) {
    active += ssid7.value[0];
  }
  
  if (ssid8 && ssid8.size && isNumber(ssid8.value[0])) {
    active += ssid8.value[0];
  }
}
return {writable: false, value: active};
