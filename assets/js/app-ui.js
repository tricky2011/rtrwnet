(function () {
    var nowNodes = document.querySelectorAll('[data-now-year]');
    var year = new Date().getFullYear();
    nowNodes.forEach(function (node) {
        node.textContent = String(year);
    });

    var refreshNodes = document.querySelectorAll('[data-now-time]');
    refreshNodes.forEach(function (node) {
        node.textContent = new Date().toLocaleString('id-ID');
    });
})();

(function (window, document) {
    function pickFirstText(values) {
        if (!Array.isArray(values)) {
            return '';
        }

        for (var i = 0; i < values.length; i += 1) {
            var text = String(values[i] || '').trim();
            if (text !== '') {
                return text;
            }
        }

        return '';
    }

    function normalizePhoneValue(value) {
        var raw = String(value || '').trim();
        if (raw === '') {
            return '';
        }

        var digitsOnly = raw.replace(/\D/g, '');
        if (digitsOnly === '') {
            return '';
        }

        return raw.charAt(0) === '+' ? ('+' + digitsOnly) : digitsOnly;
    }

    function dispatchFieldEvents(field) {
        if (!field) {
            return;
        }

        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function applyHint(hintEl, message, tone) {
        if (!hintEl) {
            return;
        }

        hintEl.classList.remove('text-muted', 'text-success', 'text-danger', 'text-warning');

        if (tone === 'success') {
            hintEl.classList.add('text-success');
        } else if (tone === 'danger') {
            hintEl.classList.add('text-danger');
        } else if (tone === 'warning') {
            hintEl.classList.add('text-warning');
        } else {
            hintEl.classList.add('text-muted');
        }

        hintEl.textContent = String(message || '');
    }

    function setButtonBusyState(buttonEl, isBusy) {
        if (!buttonEl) {
            return;
        }

        var defaultLabel = buttonEl.getAttribute('data-default-label');
        if (!defaultLabel) {
            defaultLabel = String(buttonEl.textContent || 'Kontak').trim() || 'Kontak';
            buttonEl.setAttribute('data-default-label', defaultLabel);
        }

        buttonEl.disabled = !!isBusy;
        buttonEl.textContent = isBusy ? 'Memuat...' : defaultLabel;
    }

    var CONTACT_PICKER_LOG_PREFIX = '[PHONE_CONTACT_PICKER]';

    function logContactPicker(level, message, payload) {
        if (!window.console) {
            return;
        }

        var methodName = typeof window.console[level] === 'function' ? level : 'log';

        if (typeof payload === 'undefined') {
            window.console[methodName](CONTACT_PICKER_LOG_PREFIX, message);
            return;
        }

        window.console[methodName](CONTACT_PICKER_LOG_PREFIX, message, payload);
    }

    function isContactPickerSupported() {
        return !!(
            window.isSecureContext &&
            window.navigator &&
            window.navigator.contacts &&
            typeof window.navigator.contacts.select === 'function'
        );
    }

    function isCapacitorNativePlatform() {
        if (!window.Capacitor) {
            return false;
        }

        if (typeof window.Capacitor.isNativePlatform === 'function') {
            return !!window.Capacitor.isNativePlatform();
        }

        if (typeof window.Capacitor.getPlatform === 'function') {
            return window.Capacitor.getPlatform() !== 'web';
        }

        return false;
    }

    function getCapacitorPlatformName() {
        if (!window.Capacitor || typeof window.Capacitor.getPlatform !== 'function') {
            return '';
        }

        return String(window.Capacitor.getPlatform() || '');
    }

    function getContactPickerRuntimeMode() {
        if (isCapacitorNativePlatform()) {
            return 'native';
        }

        if (isContactPickerSupported()) {
            return 'browser';
        }

        return 'unsupported';
    }

    function registerCapacitorContactsPlugin() {
        if (!window.Capacitor || typeof window.Capacitor.registerPlugin !== 'function') {
            return null;
        }

        try {
            var contactsPlugin = window.Capacitor.registerPlugin('Contacts');

            logContactPicker('debug', 'Mencoba register plugin Contacts via Capacitor.registerPlugin().', {
                hasPlugin: !!contactsPlugin,
                isPluginAvailable: typeof window.Capacitor.isPluginAvailable === 'function'
                    ? window.Capacitor.isPluginAvailable('Contacts')
                    : null
            });

            return contactsPlugin || null;
        } catch (error) {
            logContactPicker('warn', 'Gagal register plugin Contacts via Capacitor.registerPlugin().', error);
            return null;
        }
    }

    function getCapacitorContactsPlugin() {
        if (!isCapacitorNativePlatform()) {
            return null;
        }

        if (!window.Capacitor) {
            logContactPicker('warn', 'window.Capacitor is undefined.');
            return null;
        }

        var contactsPlugin = null;

        if (window.Capacitor.Plugins && window.Capacitor.Plugins.Contacts) {
            contactsPlugin = window.Capacitor.Plugins.Contacts;
        } else {
            contactsPlugin = registerCapacitorContactsPlugin();
        }

        if (!contactsPlugin && window.Capacitor.Plugins) {
            contactsPlugin = registerCapacitorContactsPlugin();
        }

        if (!contactsPlugin) {
            logContactPicker('warn', 'window.Capacitor.Plugins.Contacts is undefined setelah proses register.', {
                plugins: window.Capacitor.Plugins,
                isPluginAvailable: typeof window.Capacitor.isPluginAvailable === 'function'
                    ? window.Capacitor.isPluginAvailable('Contacts')
                    : null,
                hasRegisterPlugin: typeof window.Capacitor.registerPlugin === 'function'
            });
            return null;
        }

        if (typeof contactsPlugin.pickContact === 'function') {
            return contactsPlugin;
        }

        logContactPicker('warn', 'Contacts plugin ditemukan tetapi method pickContact() tidak tersedia.', contactsPlugin);

        return null;
    }

    function isCapacitorContactsSupported() {
        return !!getCapacitorContactsPlugin();
    }

    function getDisplayNameFromCapacitorContact(contact) {
        var name = contact && contact.name ? contact.name : {};
        var displayName = String(name.display || '').trim();

        if (displayName !== '') {
            return displayName;
        }

        return pickFirstText([name.given, name.middle, name.family]);
    }

    function getPhoneNumberFromCapacitorContact(contact) {
        var phones = contact && Array.isArray(contact.phones) ? contact.phones : [];

        for (var i = 0; i < phones.length; i += 1) {
            var phone = phones[i] || {};
            var number = normalizePhoneValue(phone.number);

            if (number !== '') {
                return number;
            }
        }

        return '';
    }

    function isPluginNotImplementedError(error) {
        var message = error && error.message ? String(error.message).toLowerCase() : '';

        return message.indexOf('not implemented') !== -1 ||
            message.indexOf('does not have web implementation') !== -1 ||
            message.indexOf('plugin contacts is not implemented') !== -1;
    }

    async function ensureCapacitorContactsPermission(capacitorContacts) {
        var permissions = null;
        var permissionState = '';

        if (typeof capacitorContacts.checkPermissions === 'function') {
            permissions = await capacitorContacts.checkPermissions();
            permissionState = permissions && permissions.contacts ? String(permissions.contacts) : '';
            logContactPicker('debug', 'Hasil checkPermissions()', permissions);
        } else {
            logContactPicker('warn', 'Method checkPermissions() tidak tersedia pada Contacts plugin.');
        }

        if (
            permissionState !== 'granted' &&
            permissionState !== 'limited' &&
            typeof capacitorContacts.requestPermissions === 'function'
        ) {
            permissions = await capacitorContacts.requestPermissions();
            permissionState = permissions && permissions.contacts ? String(permissions.contacts) : '';
            logContactPicker('debug', 'Hasil requestPermissions()', permissions);
        }

        if (permissionState !== '' && permissionState !== 'granted' && permissionState !== 'limited') {
            var permissionError = new Error('Contacts permission was not granted.');
            permissionError.name = 'NotAllowedError';
            throw permissionError;
        }

        return permissionState;
    }

    async function selectPhoneContact() {
        var runtimeMode = getContactPickerRuntimeMode();

        logContactPicker('debug', 'selectPhoneContact() dipanggil.', {
            runtimeMode: runtimeMode,
            capacitorPlatform: getCapacitorPlatformName(),
            isSecureContext: !!window.isSecureContext
        });

        if (runtimeMode === 'native') {
            var capacitorContacts = getCapacitorContactsPlugin();

            if (!capacitorContacts) {
                var missingPluginError = new Error('Contacts plugin tidak tersedia pada runtime native.');
                missingPluginError.name = 'NotSupportedError';
                throw missingPluginError;
            }

            await ensureCapacitorContactsPermission(capacitorContacts);

            var pickedContactResult = await capacitorContacts.pickContact({
                projection: {
                    name: true,
                    phones: true
                }
            });
            logContactPicker('debug', 'Hasil pickContact()', pickedContactResult);
            var pickedContact = pickedContactResult && pickedContactResult.contact ? pickedContactResult.contact : null;

            if (!pickedContact) {
                var cancelledNativeSelection = new Error('Contact selection was cancelled.');
                cancelledNativeSelection.name = 'AbortError';
                throw cancelledNativeSelection;
            }

            return {
                phoneValue: getPhoneNumberFromCapacitorContact(pickedContact),
                contactName: getDisplayNameFromCapacitorContact(pickedContact)
            };
        }

        if (runtimeMode !== 'browser') {
            var unsupportedError = new Error('Contact picker is unavailable on this device.');
            unsupportedError.name = 'NotSupportedError';
            throw unsupportedError;
        }

        logContactPicker('debug', 'Menggunakan navigator.contacts.select() untuk mode browser/PWA.');
        var contacts = await window.navigator.contacts.select(['name', 'tel'], { multiple: false });
        logContactPicker('debug', 'Hasil navigator.contacts.select()', contacts);
        if (!Array.isArray(contacts) || contacts.length === 0) {
            var cancelledSelection = new Error('Contact selection was cancelled.');
            cancelledSelection.name = 'AbortError';
            throw cancelledSelection;
        }

        var selectedContact = contacts[0] || {};

        return {
            phoneValue: normalizePhoneValue(pickFirstText(selectedContact.tel)),
            contactName: pickFirstText(selectedContact.name)
        };
    }

    function initDefaultPhoneContactPicker() {
        if (typeof window.NawacorePhoneContactPicker !== 'function') {
            return { supported: false };
        }

        var phoneInput = document.getElementById('phone');
        var buttonEl = document.getElementById('phone_contact_picker_btn');
        if (!phoneInput || !buttonEl) {
            return { supported: false };
        }

        return window.NawacorePhoneContactPicker({
            phoneInputId: 'phone',
            contactButtonId: 'phone_contact_picker_btn',
            contactHintId: 'phone_contact_picker_hint',
            nameInputId: 'full_name'
        });
    }

    window.NawacorePhoneContactPicker = function (options) {
        var settings = options || {};
        var phoneInput = document.getElementById(String(settings.phoneInputId || ''));

        if (!phoneInput) {
            return { supported: false };
        }

        var buttonEl = document.getElementById(String(settings.contactButtonId || ''));
        var hintEl = document.getElementById(String(settings.contactHintId || ''));
        var nameInput = document.getElementById(String(settings.nameInputId || ''));
        var runtimeMode = getContactPickerRuntimeMode();
        var hasCapacitorContactsPlugin = isCapacitorContactsSupported();
        var hasBrowserContactsApi = isContactPickerSupported();
        var supported = false;
        var unsupportedMessage = settings.unsupportedMessage || 'Ambil kontak tersedia di aplikasi Android via plugin kontak Capacitor atau di Chrome/PWA via HTTPS.';
        var readyMessage = settings.readyMessage || 'Tap tombol Kontak untuk ambil nomor dari kontak HP.';
        var fillNameIfEmpty = settings.fillNameIfEmpty !== false;

        supported = hasCapacitorContactsPlugin || hasBrowserContactsApi;

        logContactPicker('debug', 'Inisialisasi phone contact picker.', {
            supported: supported,
            runtimeMode: runtimeMode,
            capacitorPlatform: getCapacitorPlatformName(),
            hasCapacitor: !!window.Capacitor,
            hasContactsPlugin: hasCapacitorContactsPlugin,
            hasBrowserContactsApi: hasBrowserContactsApi
        });

        phoneInput.setAttribute('inputmode', phoneInput.getAttribute('inputmode') || 'tel');
        phoneInput.setAttribute('autocomplete', phoneInput.getAttribute('autocomplete') || 'tel');

        if (!buttonEl) {
            applyHint(hintEl, supported ? readyMessage : unsupportedMessage, 'muted');
            return { supported: supported };
        }

        if (buttonEl.getAttribute('data-contact-picker-bound') === '1') {
            return { supported: supported };
        }
        buttonEl.setAttribute('data-contact-picker-bound', '1');

        if (!supported) {
            buttonEl.disabled = false;
            buttonEl.title = unsupportedMessage;
            applyHint(hintEl, unsupportedMessage, 'warning');

            buttonEl.addEventListener('click', function () {
                logContactPicker('warn', 'Contact picker tidak didukung pada runtime ini.', {
                    runtimeMode: runtimeMode,
                    capacitorPlatform: getCapacitorPlatformName(),
                    hasCapacitor: !!window.Capacitor
                });

                if (typeof phoneInput.focus === 'function') {
                    phoneInput.focus();
                }
                if (typeof phoneInput.select === 'function') {
                    phoneInput.select();
                }

                applyHint(hintEl, unsupportedMessage + ' Isi nomor manual pada kolom No. HP.', 'warning');
            });

            return { supported: false };
        }

        buttonEl.disabled = false;
        buttonEl.removeAttribute('title');
        applyHint(hintEl, readyMessage, 'muted');

        buttonEl.addEventListener('click', async function () {
            setButtonBusyState(buttonEl, true);

            try {
                var selectedContact = await selectPhoneContact();
                var phoneValue = normalizePhoneValue(selectedContact.phoneValue);
                var contactName = pickFirstText([selectedContact.contactName]);

                if (phoneValue === '') {
                    applyHint(hintEl, 'Kontak terpilih belum punya nomor HP yang bisa dipakai.', 'warning');
                    return;
                }

                phoneInput.value = phoneValue;
                dispatchFieldEvents(phoneInput);

                if (fillNameIfEmpty && nameInput && String(nameInput.value || '').trim() === '' && contactName !== '') {
                    nameInput.value = contactName;
                    dispatchFieldEvents(nameInput);
                }

                applyHint(hintEl, 'Nomor HP berhasil diambil dari kontak.', 'success');
            } catch (error) {
                var errorName = error && error.name ? String(error.name) : '';
                var errorMessage = error && error.message ? String(error.message) : '';
                var currentRuntimeMode = getContactPickerRuntimeMode();

                logContactPicker('warn', 'Gagal mengambil kontak.', {
                    name: errorName,
                    message: errorMessage,
                    error: error,
                    runtimeMode: currentRuntimeMode
                });

                if (errorName === 'AbortError') {
                    applyHint(hintEl, 'Pemilihan kontak dibatalkan.', 'muted');
                } else if (errorName === 'NotAllowedError' || errorName === 'SecurityError') {
                    applyHint(hintEl, 'Akses kontak ditolak. Izinkan akses kontak di browser/aplikasi HP.', 'danger');
                } else if (
                    isPluginNotImplementedError(error) ||
                    (
                        errorName === 'NotSupportedError' &&
                        currentRuntimeMode === 'native'
                    )
                ) {
                    applyHint(hintEl, 'Plugin kontak native belum aktif. Pastikan plugin terinstall, sudah `npx cap sync`, permission Android sudah benar, dan dicoba di device asli.', 'warning');
                } else if (errorMessage.toLowerCase().indexOf('capacitor is undefined') !== -1) {
                    applyHint(hintEl, 'Capacitor bridge belum termuat. Pastikan halaman dibuka dari aplikasi Capacitor, bukan browser biasa.', 'danger');
                } else if (errorMessage.toLowerCase().indexOf('contacts is undefined') !== -1) {
                    applyHint(hintEl, 'Objek Contacts plugin belum tersedia. Cek instalasi plugin dan jalankan `npx cap sync`.', 'warning');
                } else if (errorName === 'NotSupportedError') {
                    applyHint(hintEl, unsupportedMessage + ' Isi nomor manual pada kolom No. HP.', 'warning');
                } else {
                    applyHint(hintEl, 'Gagal membaca kontak HP di perangkat ini.', 'danger');
                }
            } finally {
                setButtonBusyState(buttonEl, false);
            }
        });

        return { supported: true };
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDefaultPhoneContactPicker);
    } else {
        initDefaultPhoneContactPicker();
    }
})(window, document);
