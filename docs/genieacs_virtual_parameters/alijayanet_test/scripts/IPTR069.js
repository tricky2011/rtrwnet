// TR069 IP
let result = '';

if ("value" in args[1]) {
    result = args[1].value[0];
}

if (result === '' || result === '0.0.0.0') {
    let keys = [
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress',
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.2.ExternalIPAddress',
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.3.ExternalIPAddress',
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.4.ExternalIPAddress',
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANIPConnection.1.ExternalIPAddress',
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANIPConnection.2.ExternalIPAddress',
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.3.WANIPConnection.1.ExternalIPAddress',
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.4.WANIPConnection.1.ExternalIPAddress',
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.5.WANIPConnection.1.ExternalIPAddress',
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.5.WANIPConnection.1.ExternalIPAddress',
        'InternetGatewayDevice.WANDevice.*.WANConnectionDevice.*.WANIPConnection.*.ExternalIPAddress',
        'Device.IP.Interface.1.IPv4Address.1.IPAddress'
    ];

    result = getParameterValue(keys);
}

return {writable: false, value: [result, "xsd:string"]};

function getParameterValue(keys) {
    for (let key of keys) {
        if (key.includes('ExternalIPAddress')) {
            let connectionTypeKey = key.replace('ExternalIPAddress', 'ConnectionType');
            let connectionType = declare(connectionTypeKey, {value: Date.now()});
            if (connectionType.size && connectionType.value[0] === 'bridge') {
                continue;
            }
        }

        let d = declare(key, {path: Date.now() - (120 * 1000), value: Date.now()});

        for (let item of d) {
            if (item.value && item.value[0] && item.value[0] !== '0.0.0.0') {
                return item.value[0];
            }
        }
    }

    return '';
}
