// pppoe User (index page)
let result = '';

if ("value" in args[1]) {
    result = args[1].value[0];
}

if (result === '') {
    let keys = [
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Username',
        'InternetGatewayDevice.WANDevice.*.WANConnectionDevice.1.WANPPPConnection.2.Username',
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANPPPConnection.1.Username',
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANPPPConnection.2.Username',
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.3.WANPPPConnection.1.Username',
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.3.WANPPPConnection.2.Username',
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.4.WANPPPConnection.1.Username',
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.4.WANPPPConnection.2.Username',
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.5.WANPPPConnection.1.Username',
        'InternetGatewayDevice.WANDevice.*.WANConnectionDevice.*.WANPPPConnection.*.Username',
    ];

    result = getParameterValue(keys);
}

log("pppoeIP: " + result);
return {writable: false, value: [result, "xsd:string"]};

function getParameterValue(keys) {
    for (let key of keys) {
        if (key.includes('WANPPPConnection.1.Username')) {
            let connectionTypeKey = key.replace('Username', 'ConnectionType');
            let connectionType = declare(connectionTypeKey, {value: Date.now()});
            if (connectionType.size && connectionType.value[0] === 'PPPoE_Bridged') {
                continue;
            }
        }

        let d = declare(key, {path: Date.now() - (120 * 1000), value: Date.now()});

        for (let item of d) {
            if (item.value && item.value[0] && item.value[0] !== '') {
                return item.value[0];
            }
        }
    }

    return '';
}
