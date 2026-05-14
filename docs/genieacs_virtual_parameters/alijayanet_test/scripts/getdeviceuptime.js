// Device Uptime
let totalSecs = "";
const brand = declare('DeviceID.Manufacturer', {value: 1}).value[0];

if (args[1].value) {
  totalSecs = args[1].value[0];
  if (brand !== 'MikroTik') {
    declare("InternetGatewayDevice.DeviceInfo.UpTime", null, {value: totalSecs});
  } else {
    declare("Device.DeviceInfo.UpTime", null, {value: totalSecs});
  }
}
else {
  if (brand !== 'MikroTik') {
    totalSecs = declare("InternetGatewayDevice.DeviceInfo.UpTime", {value: Date.now()}).value[0];
  } else {
    totalSecs = declare("Device.DeviceInfo.UpTime", {value: Date.now()}).value[0];
  }
}
let days = Math.floor(totalSecs / 86400);
let rem  = totalSecs % 86400;
let hrs  = Math.floor(rem / 3600);
if (hrs < 10) {
	hrs = "0" + hrs;
}

rem  = rem % 3600;
let mins = Math.floor(rem / 60);
if (mins < 10) {
	mins = "0" + mins;
}
let secs = rem % 60;
if (secs < 10) {
	secs = "0" + secs;
}

let up = days + "d " + hrs + ":" + mins + ":" + secs;

return {writable: false, value: up};
