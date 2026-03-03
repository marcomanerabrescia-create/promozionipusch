// ========================================
        // 🔑 SISTEMA DINAMICO TOELETTATURA
        // ========================================
        let AGENDA_ID = 'TEMP'; // Temporaneo, verrà sostituito dal codice attivazione
        console.log('PRIVACY CHECK ALL START:', {
            ls1: localStorage.getItem('privacyAccepted'),
            cookie: document.cookie,
            IS_STANDALONE: window.matchMedia('(display-mode: standalone)').matches
        });
        try {
            const cap = window.Capacitor;
            const capPlatform = cap?.platform || 'unknown';
            const capIsNative = cap?.isNativePlatform ? cap.isNativePlatform() : false;
            console.log('[PUSH DEBUG] Platform detect', {
                hasCapacitor: !!cap,
                capPlatform,
                capIsNative,
                userAgent: navigator.userAgent
            });
        } catch (e) {
            console.warn('[PUSH DEBUG] Platform detect error', e);
        }
        let VET_CONFIG = null; // Configurazione toilettatore caricata dal JSON
        const ATTIVAZIONE_OBBLIGATORIA = false; // Se false, non blocca il flusso anche senza codice

        // Registrazione push native (Capacitor) e invio token FCM al backend
        async function registerNativePush() {
            const Push = window.Capacitor?.Plugins?.PushNotifications;
            if (!Push) {
                console.log('[PUSH DEBUG] Capacitor PushNotifications non presente, salto');
                return;
            }

            try {
                console.log('[PUSH DEBUG] registerNativePush start, isNative=', window.Capacitor?.isNativePlatform ? window.Capacitor.isNativePlatform() : 'n/a', 'platform=', window.Capacitor?.platform);
                console.log('[PUSH DEBUG] registerNativePush window.Capacitor=', window.Capacitor);
            } catch (_) {}

            // Chiede permesso e registra
            try {
                console.log('[PUSH DEBUG] requestPermissions()...');
                await Push.requestPermissions();
                console.log('[PUSH DEBUG] requestPermissions OK');
            } catch (e) {
                console.error('[PUSH DEBUG] requestPermissions ERROR', e);
            }
            try {
                console.log('[PUSH DEBUG] register()...');
                await Push.register();
                console.log('[PUSH DEBUG] register OK');
                try {
                  const delivered = await Push.getDeliveredNotifications();
                  const n = delivered?.notifications?.[0];
                  const url = n?.data?.url;
                  console.log('[PUSH DEBUG] delivered count=', delivered?.notifications?.length || 0, 'url=', url || '');
                } catch (e) {
                  console.error('[PUSH DEBUG] getDeliveredNotifications error', e);
                }
            } catch (e) {
                console.error('[PUSH DEBUG] register ERROR', e);
            }

            // Invio token solo se attivato (activationCode + activationPhone presenti)
            Push.addListener('registration', async (token) => {
                const ristoranteId = localStorage.getItem('activationCode');
                const clienteId = localStorage.getItem('activationPhone');
                const deviceId = ristoranteId ? generateDeviceId(ristoranteId) : '';
                try { console.log('[PUSH DEBUG] registration event token=', token?.value, 'code=', ristoranteId, 'phone=', clienteId, 'deviceId=', deviceId); } catch (_) {}
                if (!ristoranteId || !clienteId) {
                    console.warn('Token FCM ottenuto ma utente non attivato, skip send');
                    return;
                }
                try {
                    const resp = await fetch('https://puschpromozioni.it/ristorantemimmo1/PRENOTAZIONI/register_push_native.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            codice_attivazione: ristoranteId,
                            telefono: clienteId,
                            fcm_token: token.value
                        })
                    });
                    const text = await resp.text();
                    console.log('[PUSH DEBUG] register_push_native resp', resp.status, text);
                } catch (e) {
                    console.error('[PUSH DEBUG] Invio token FCM fallito', e);
                }
            });

        Push.addListener('registrationError', (err) => {
            console.error('[PUSH DEBUG] Errore registrazione push', err);
        });

        Push.addListener('pushNotificationReceived', (notification) => {
            console.log('[PUSH DEBUG] received foreground', JSON.stringify(notification));
            const data = notification.data || {};
            if (data.type === 'conferma' && data.data && data.ora) {
                const messaggio = `✅ Appuntamento confermato per il ${data.data} alle ${data.ora}. Ti aspettiamo!`;
                if (typeof addToPopupQueue === 'function') {
                    addToPopupQueue('push_conferma_' + Date.now(), messaggio, false);
                }
            } else {
                if (typeof loadAppointments === 'function') {
                    loadAppointments().then(() => checkForConfirmedAppointments());
                } else {
                    checkForConfirmedAppointments();
                }
            }
        });

        Push.addListener('pushNotificationActionPerformed', (action) => {
            console.log('[PUSH DEBUG] action performed', JSON.stringify(action));
            try {
                const url = action?.notification?.data?.url;
                if (url) {
                    window.location.href = url;
                }
            } catch (e) {
                console.error('[PUSH DEBUG] pushNotificationActionPerformed error', e);
            }
        });
    }
        
        // Backup/restore dati chiave (activationCode/Phone, privacyAccepted) su cookie
        function backupToCookie(name, value) {
            try {
                const expires = new Date(Date.now() + 365*24*60*60*1000).toUTCString();
                document.cookie = `${encodeURIComponent(name)}=${encodeURIComponent(value || '')}; expires=${expires}; path=/`;
            } catch (e) {
                console.warn('Cookie backup failed:', e);
            }
        }
        function readFromCookie(name) {
            try {
                const match = document.cookie.split(';').map(s => s.trim()).find(s => s.startsWith(encodeURIComponent(name) + '='));
                if (!match) return '';
                return decodeURIComponent(match.split('=')[1]);
            } catch (_) {
                return '';
            }
        }
        const ACTIVATION_BACKUP_KEYS = [
            'activationCode',
            'activationPhone',
            'privacyAccepted',
            'device_id_base'
        ];

        function isActivationKey(key) {
            if (!key) return false;
            if (ACTIVATION_BACKUP_KEYS.indexOf(key) !== -1) return true;
            if (key.indexOf('activation') !== -1) return true;
            if (key.indexOf('privacy') !== -1) return true;
            return false;
        }

        function collectBackupData() {
            const data = {};
            try {
                for (let i = 0; i < localStorage.length; i++) {
                    const key = localStorage.key(i);
                    if (!isActivationKey(key)) continue;
                    const value = localStorage.getItem(key);
                    if (value !== null && value !== undefined && value !== '') {
                        data[key] = value;
                    }
                }
            } catch (e) {
                console.warn('Collect backup data failed:', e);
            }
            return data;
        }

        function applyBackupData(data) {
            if (!data || typeof data !== 'object') return;
            try {
                Object.keys(data).forEach(key => {
                    const value = data[key];
                    if (value !== null && value !== undefined && value !== '') {
                        localStorage.setItem(key, value);
                    }
                });
            } catch (e) {
                console.warn('Apply backup data failed:', e);
            }
        }

        function hasLocalActivationData() {
            try {
                const code = localStorage.getItem('activationCode') || '';
                const phone = localStorage.getItem('activationPhone') || '';
                const baseId = localStorage.getItem('device_id_base') || '';
                return !!(code && phone && baseId);
            } catch (_) {
                return false;
            }
        }

        function backupToIndexedDB(data) {
            return new Promise((resolve) => {
                if (!window.indexedDB) return resolve(false);
                const req = indexedDB.open('agendaClienteDB', 2);
                req.onupgradeneeded = function() {
                    const db = req.result;
                    if (!db.objectStoreNames.contains('activationData')) {
                        db.createObjectStore('activationData', { keyPath: 'id' });
                    }
                };
                req.onerror = function() { resolve(false); };
                req.onsuccess = function() {
                    const db = req.result;
                    const tx = db.transaction('activationData', 'readwrite');
                    const store = tx.objectStore('activationData');
                    store.put({ id: 'activation', data: data, updatedAt: Date.now() });
                    tx.oncomplete = function() { db.close(); resolve(true); };
                    tx.onerror = function() { db.close(); resolve(false); };
                };
            });
        }

        function readFromIndexedDB() {
            return new Promise((resolve) => {
                if (!window.indexedDB) return resolve(null);
                const req = indexedDB.open('agendaClienteDB', 2);
                req.onupgradeneeded = function() {
                    const db = req.result;
                    if (!db.objectStoreNames.contains('activationData')) {
                        db.createObjectStore('activationData', { keyPath: 'id' });
                    }
                };
                req.onerror = function() { resolve(null); };
                req.onsuccess = function() {
                    const db = req.result;
                    if (!db.objectStoreNames.contains('activationData')) {
                        db.close();
                        return resolve(null);
                    }
                    const tx = db.transaction('activationData', 'readonly');
                    const store = tx.objectStore('activationData');
                    const getReq = store.get('activation');
                    getReq.onsuccess = function() {
                        const row = getReq.result;
                        db.close();
                        resolve(row && row.data ? row.data : null);
                    };
                    getReq.onerror = function() {
                        db.close();
                        resolve(null);
                    };
                };
            });
        }

        function backupToCache(data) {
            if (!('caches' in window)) return Promise.resolve(false);
            const payload = { data: data || {}, updatedAt: Date.now() };
            return caches.open('activation-backup').then(cache => {
                const req = new Request('activation-backup');
                const body = JSON.stringify(payload);
                return cache.put(req, new Response(body, {
                    headers: { 'Content-Type': 'application/json' }
                })).then(() => true).catch(() => false);
            }).catch(() => false);
        }

        function readFromCache() {
            if (!('caches' in window)) return Promise.resolve(null);
            return caches.open('activation-backup').then(cache => {
                return cache.match('activation-backup').then(resp => {
                    if (!resp) return null;
                    return resp.text().then(text => {
                        try {
                            const payload = JSON.parse(text);
                            return (payload && payload.data) ? payload.data : null;
                        } catch (_) {
                            return null;
                        }
                    });
                });
            }).catch(() => null);
        }

        function extractBaseFromDeviceId(deviceId) {
            if (!deviceId) return '';
            const idx = deviceId.lastIndexOf('-');
            if (idx === -1 || idx + 1 >= deviceId.length) return '';
            return deviceId.substring(idx + 1);
        }

        function applyServerActivationData(data) {
            if (!data || typeof data !== 'object') return;
            try {
                if (data.activationCode) localStorage.setItem('activationCode', data.activationCode);
                if (data.telefono) localStorage.setItem('activationPhone', data.telefono);
                if (data.privacyAccepted !== undefined && data.privacyAccepted !== null && data.privacyAccepted !== '') {
                    localStorage.setItem('privacyAccepted', String(data.privacyAccepted));
                }
                const baseFromServer = data.device_id_base || extractBaseFromDeviceId(data.device_id || '');
                if (baseFromServer) {
                    localStorage.setItem('device_id_base', baseFromServer);
                }
            } catch (e) {
                console.warn('Apply server activation failed:', e);
            }
        }

        function fetchActivationFromServer(deviceId) {
            if (!deviceId) return Promise.resolve(null);
            const url = 'get_activation.php?device_id=' + encodeURIComponent(deviceId);
            return fetch(url, { cache: 'no-store' })
                .then(resp => resp.json())
                .then(payload => {
                    console.log('[BACKUP] get_activation response:', payload, 'deviceId=', deviceId);
                    if (payload && payload.success && payload.data) {
                        console.log('[PUSH DEBUG] post-get_activation success: capNative=', window.Capacitor?.isNativePlatform ? window.Capacitor.isNativePlatform() : 'n/a', 'code=', localStorage.getItem('activationCode'), 'phone=', localStorage.getItem('activationPhone'));
                        return payload.data;
                    }
                    return null;
                })
                .catch(() => null);
        }

        let backupTimer = null;
        function salvaBackupDati() {
            const data = collectBackupData();
            try {
                Object.keys(data).forEach(key => backupToCookie(key, data[key]));
            } catch (e) {
                console.warn('Cookie bulk backup failed:', e);
            }
            return Promise.all([backupToIndexedDB(data), backupToCache(data)]).then(() => true).catch(() => false);
        }

        function scheduleBackup() {
            if (backupTimer) clearTimeout(backupTimer);
            backupTimer = setTimeout(() => {
                salvaBackupDati();
            }, 150);
        }

        function ripristinaBackupDati() {
            try { console.log('[BACKUP] Start ripristino'); } catch (_) {}
            if (hasLocalActivationData()) return;
            try { console.log('[BACKUP] localStorage VUOTO'); } catch (_) {}
            const cookieData = {};
            try {
                ACTIVATION_BACKUP_KEYS.forEach(key => {
                    const v = readFromCookie(key);
                    if (v) cookieData[key] = v;
                });
            } catch (_) {}
            if (Object.keys(cookieData).length > 0) {
                try { console.log('[BACKUP] Trovati COOKIE!'); } catch (_) {}
                applyBackupData(cookieData);
                scheduleBackup();
                return;
            }
            readFromIndexedDB().then(data => {
                if (data && Object.keys(data).length > 0) {
                    try { console.log('[BACKUP] Trovato IndexedDB!'); } catch (_) {}
                    applyBackupData(data);
                    scheduleBackup();
                    return;
                }
                readFromCache().then(cacheData => {
                    if (cacheData && Object.keys(cacheData).length > 0) {
                        try { console.log('[BACKUP] Trovata CACHE!'); } catch (_) {}
                        applyBackupData(cacheData);
                        scheduleBackup();
                        return;
                    }
                    const baseId = getDeviceIdBase();
                    try { console.log('[BACKUP] Chiamo SERVER'); } catch (_) {}
                    fetchActivationFromServer(baseId).then(serverData => {
                        if (serverData && Object.keys(serverData).length > 0) {
                            try { console.log('[BACKUP] Server OK!'); } catch (_) {}
                            applyServerActivationData(serverData);
                            salvaBackupDati();
                            return;
                        }
                        try { console.log('[BACKUP] NESSUN DATO trovato!'); } catch (_) {}
                    });
                });
            });
        }

        (function installBackupHooks() {
            try {
                const origSetItem = localStorage.setItem.bind(localStorage);
                localStorage.setItem = function(key, value) {
                    origSetItem(key, value);
                    if (isActivationKey(key)) scheduleBackup();
                };
            } catch (e) {
                console.warn('localStorage hook failed:', e);
            }
            try { console.log('[BACKUP] installBackupHooks -> ripristina'); } catch (_) {}
            ripristinaBackupDati();
        })();
        // Funzione di navigazione robusta (funziona su tutti i dispositivi)
        function navigaRobusta(url) {
            console.log('[NAV] Navigazione verso:', url);
            
            // Metodo 1: location.replace (più affidabile di href)
            try {
                window.location.replace(url);
                return true;
            } catch (e1) {
                console.warn('[NAV] replace fallito:', e1);
            }
            
            // Metodo 2: location.assign
            try {
                window.location.assign(url);
                return true;
            } catch (e2) {
                console.warn('[NAV] assign fallito:', e2);
            }
            
            // Metodo 3: href (ultimo tentativo)
            try {
                window.location.href = url;
                return true;
            } catch (e3) {
                console.error('[NAV] Tutti i metodi falliti:', e3);
                alert('Errore navigazione. Ricarica l\'app.');
                return false;
            }
        }
        
        function openWelcomeFromInfo() {
            if (typeof ActionDispatcher !== 'undefined' && ActionDispatcher && typeof ActionDispatcher.dispatch === 'function') {
                ActionDispatcher.dispatch('openWelcomeModal');
            }
        }
        
        
        function openWelcomeModal() {
            const modal = document.getElementById('welcome-modal');
            if (modal) {
                modal.classList.add('show');
            } else {
                console.warn('Welcome modal non trovato');
            }
        }

        function closeWelcomeModal() {
            const modal = document.getElementById('welcome-modal');
            if (modal) {
                modal.classList.remove('show');
            }
        }

        function openIosHelp(event) {
            if (event) event.preventDefault();
            const modal = document.getElementById('iosHelpModal');
            if (modal) {
                modal.classList.add('show');
                modal.style.display = 'flex';
            }
        }

        function closeIosHelp(event) {
            if (event) event.preventDefault();
            const modal = document.getElementById('iosHelpModal');
            if (modal) {
                modal.classList.remove('show');
                modal.style.display = 'none';
            }
        }

        function closeWelcomeAndFocusInstall() {
            closeWelcomeModal();
            const installBtn = document.getElementById('installBtn');
            if (installBtn) {
                installBtn.focus();
                installBtn.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }

        // Funzione per estrarre il prefisso dal codice (es: AMADORI-VET123 → AMADORI)
        function getPrefissoFromCodice(codice) {
            if (!codice || typeof codice !== 'string') return null;
            const parts = codice.split('-');
            return parts.length > 0 ? parts[0].toUpperCase() : null;
        }

        // Rileva modalità PWA vs muletto (browser)
        const IS_STANDALONE = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
        const IS_MULETTO = !IS_STANDALONE;
        const FORCE_BROWSER_MODE = true;
        const VOICE_APK_URL = 'https://www.puschpromozioni.it/sveglia-vocale/app-debug.apk'; // Link APK agenda vocale

        // DISABILITATO: Non cancellare il codice in modalità muletto
        // Questo causava la perdita del codice di attivazione
        // if (IS_MULETTO) {
        //     try {
        //         localStorage.removeItem('activationCode');
        //         localStorage.removeItem('activationPhone');
        //     } catch (_) {}
        // }

        // Funzioni helper per localStorage con prefisso unico
        const storage = {
            setItem: (key, value) => localStorage.setItem(AGENDA_ID + '_' + key, value),
            getItem: (key) => localStorage.getItem(AGENDA_ID + '_' + key),
            removeItem: (key) => localStorage.removeItem(AGENDA_ID + '_' + key),
            clear: () => {
                // Cancella solo le chiavi di questa agenda
                Object.keys(localStorage).forEach(key => {
                    if (key.startsWith(AGENDA_ID + '_')) {
                        localStorage.removeItem(key);
                    }
                });
            }
        };
        
        function getActivationData() {
            let codePlain = '';
            let phonePlain = '';
            let codePref = '';
            let phonePref = '';
            try {
                codePlain = localStorage.getItem('activationCode') || '';
                phonePlain = localStorage.getItem('activationPhone') || '';
            } catch (_) {}
            try {
                codePref = storage.getItem('activationCode') || '';
                phonePref = storage.getItem('activationPhone') || '';
            } catch (_) {}
            if (!codePlain && codePref) {
                try { localStorage.setItem('activationCode', codePref); } catch (_) {}
                codePlain = codePref;
            }
            if (!phonePlain && phonePref) {
                try { localStorage.setItem('activationPhone', phonePref); } catch (_) {}
                phonePlain = phonePref;
            }
            if (codePlain && !codePref) {
                try { storage.setItem('activationCode', codePlain); } catch (_) {}
            }
            if (phonePlain && !phonePref) {
                try { storage.setItem('activationPhone', phonePlain); } catch (_) {}
            }
            return { code: codePlain || codePref || '', phone: phonePlain || phonePref || '' };
        }
        
        // ========================================
        // SISTEMA AUTO-DIAGNOSI ERRORI
        // Controlla automaticamente il file all'avvio
        // ========================================
        (function() {
            const errors = [];
            
            // Controlla funzioni critiche del calendario
            const criticalFunctions = [
                'updateCalendar',
                'renderCustomGrid',
                'showSlotList',
                'refreshGlobalAppointments'
            ];
            
            // Verifica dopo il caricamento del DOM
            window.addEventListener('DOMContentLoaded', function() {
                console.log(' AUTO-DIAGNOSI: Controllo integrità file...');
                
                // Controlla elementi DOM critici
                const criticalElements = [
                    'calendar-container',
                    'current-month-year',
                    'slotModal',
                    'bookingForm'
                ];
                
                criticalElements.forEach(id => {
                    if (!document.getElementById(id)) {
                        errors.push(` ERRORE: Elemento DOM mancante: #${id}`);
                    }
                });
                
                // Mostra risultati
                if (errors.length > 0) {
                    console.error(' ERRORI TROVATI NEL FILE! ');
                    errors.forEach(err => console.error(err));
                    
                    // Alert visibile
                    alert(' ERRORE NEL FILE!\n\n' + errors.join('\n') + '\n\nControlla la console F12 per dettagli.');
                    
                    // Banner rosso in pagina
                    const banner = document.createElement('div');
                    banner.style.cssText = 'position:fixed;top:0;left:0;width:100%;background:#e74c3c;color:white;padding:15px;text-align:center;z-index:99999;font-weight:bold;font-size:16px;';
                    banner.innerHTML = ' ERRORE NEL FILE - Controlla Console F12 ';
                    document.body.insertBefore(banner, document.body.firstChild);
                } else {
                    console.log(' AUTO-DIAGNOSI: File OK - Nessun errore trovato');
                }
            });
            
            // Controlla funzioni dopo 2 secondi (quando tutto è caricato)
            setTimeout(function() {
                criticalFunctions.forEach(funcName => {
                    if (typeof window[funcName] !== 'function') {
                        const err = ` ERRORE: Funzione critica mancante: ${funcName}()`;
                        console.error(err);
                        if (!errors.includes(err)) {
                            errors.push(err);
                        }
                    }
                });
                
                if (errors.length > 0) {
                    console.error(' FUNZIONI MANCANTI!');
                    errors.forEach(err => console.error(err));
                }
            }, 2000);
        })();
        
        // ===== POPUP CONFERMA BELLINO =====
        function showConfirm(message, icon = '', type = 'yes') {
            return new Promise((resolve) => {
                const modal = document.getElementById('confirm-modal');
                const messageEl = document.getElementById('confirm-message');
                const iconEl = document.getElementById('confirm-icon');
                const yesBtn = document.getElementById('confirm-yes');
                const cancelBtn = document.getElementById('confirm-cancel');
                
                messageEl.textContent = message;
                iconEl.textContent = icon;
                
                // Cambia colore pulsante in base al tipo
                yesBtn.className = 'confirm-btn';
                if (type === 'delete') {
                    yesBtn.classList.add('confirm-btn-delete');
                    yesBtn.textContent = 'Elimina';
                } else {
                    yesBtn.classList.add('confirm-btn-yes');
                    yesBtn.textContent = 'Conferma';
                }
                
                modal.classList.add('show');
                
                const handleYes = () => {
                    modal.classList.remove('show');
                    cleanup();
                    resolve(true);
                };
                
                const handleCancel = () => {
                    modal.classList.remove('show');
                    cleanup();
                    resolve(false);
                };
                
                const cleanup = () => {
                    yesBtn.removeEventListener('click', handleYes);
                    cancelBtn.removeEventListener('click', handleCancel);
                };
                
                yesBtn.addEventListener('click', handleYes);
                cancelBtn.addEventListener('click', handleCancel);
            });
        }
        
        // ===== SISTEMA ATTIVAZIONE CON TENTATIVI =====
        let attemptsRemaining = 20;
        const MAX_ATTEMPTS = 20;
        
        function computeFingerprintBase() {
            try {
                const parts = [
                    navigator.userAgent || '',
                    navigator.language || '',
                    navigator.platform || '',
                    String(screen.width || ''),
                    String(screen.height || ''),
                    String(new Date().getTimezoneOffset())
                ].join('|');
                let hash = 5381;
                for (let i = 0; i < parts.length; i++) {
                    hash = ((hash << 5) + hash) + parts.charCodeAt(i);
                    hash = hash & 0x7fffffff;
                }
                return 'BASE-' + hash.toString(16);
            } catch (e) {
                return 'BASE-' + Date.now();
            }
        }

        function getDeviceIdBase() {
            let baseId = '';
            try { baseId = localStorage.getItem('device_id_base') || ''; } catch (_) {}
            if (!baseId) {
                baseId = computeFingerprintBase();
                try { localStorage.setItem('device_id_base', baseId); } catch (_) {}
            }
            return baseId || 'BASE-DEFAULT';
        }

        function generateDeviceId(codice) {
            // Genera un deviceId unico basato sul codice di attivazione
            // Cosi ogni agenda (codice diverso) ha un deviceId diverso
            const baseId = getDeviceIdBase();
            const finalDeviceId = 'DEVICE-' + codice + '-' + baseId;
            console.log('?? Device ID per codice', codice + ':', finalDeviceId);
            return finalDeviceId;
        }
        
        async function verificaAutorizzazione() {
            // Questa funzione viene chiamata SOLO se IS_STANDALONE (app installata)
            // Controlla PRIMA senza prefisso (più affidabile), poi con prefisso
            let codice = localStorage.getItem('activationCode');
            let telefono = localStorage.getItem('activationPhone');
            
            // Se non trova senza prefisso, prova con prefisso (per compatibilità)
            if (!codice || !telefono) {
                codice = storage.getItem('activationCode');
                telefono = storage.getItem('activationPhone');
            }
            
            if (!codice || !telefono) {
                if (ATTIVAZIONE_OBBLIGATORIA) {
                    const modal = document.getElementById('activation-modal');
                    const container = document.querySelector('.container');
                    if (modal) {
                        modal.classList.add('show');
                        modal.style.display = 'flex';
                    }
                    if (container) {
                        container.style.display = 'none';
                    }
                    return false;
                }
            }
            
            // Se il codice esiste in localStorage, consideralo VALIDO (già verificato in activation.php)
            if (codice) {
                const prefisso = getPrefissoFromCodice(codice);
                if (prefisso) {
                    AGENDA_ID = prefisso;
                }
            }
            
            const modal = document.getElementById('activation-modal');
            if (modal) {
                modal.classList.remove('show');
                modal.style.display = 'none';
            }
            
            const container = document.querySelector('.container');
            if (container) {
                container.style.display = 'flex';
            }
            
            console.log('✅ App già attivata - Codice:', codice);
            return true;
        }
        
        function bloccaApplicazione() {
            // Disabilitato su richiesta: non bloccare l'applicazione
            console.warn('bloccaApplicazione: blocco disabilitato, l\'app continua senza lock');
        }
        
        async function attivaApp() {
            const codice = document.getElementById('activation-code-input').value.trim().toUpperCase();
            const telefono = document.getElementById('activation-phone-input').value.trim();
            const errorEl = document.getElementById('activation-error');
            const attemptsEl = document.getElementById('attempts-info');
            const btn = document.getElementById('activation-btn');
            const codeInput = document.getElementById('activation-code-input');
            const phoneInput = document.getElementById('activation-phone-input');
            
            if (!codice || !telefono) {
                showActivationError('Inserisci sia il codice che il telefono');
                return;
            }
            
            btn.disabled = true;
            btn.textContent = 'Verifica in corso...';
            
            const deviceId = generateDeviceId(codice);
            console.log('DEBUG ATTIVAZIONE:');
            console.log('Codice:', codice);
            console.log('DeviceId:', deviceId);
            console.log('Telefono:', telefono);
            console.log(' STO PER CHIAMARE activation.php con:', {
                url: '/ristorantemimmo1/PRENOTAZIONI/activation.php',
                code: codice,
                deviceId: deviceId,
                telefono: telefono
            });
            
            try {
                    const response = await fetch('/ristorantemimmo1/PRENOTAZIONI/activation.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    cache: 'no-store',
                    body: JSON.stringify({ 
                        action: 'verify',
                        code: codice,
                        telefono: telefono,
                        deviceId: deviceId 
                    })
                });
                
                const result = await response.json();
                console.log(' RISPOSTA DA activation.php:', result);
                console.log(' HTTP Status:', response.status);
                
                if (result.success === true) {
                    // Estrai prefisso PRIMA di salvare
                    const prefisso = getPrefissoFromCodice(codice);
                    if (prefisso) {
                        AGENDA_ID = prefisso; // Imposta AGENDA_ID con il prefisso
                    }
                    
                    // Salva attivazione (persistente) per consentire registrazione push
                    localStorage.setItem('activationCode', codice);
                    localStorage.setItem('activationPhone', telefono);
                    backupToCookie('activationCode', codice);
                    backupToCookie('activationPhone', telefono);
                    // Se app nativa, registra e invia subito il token FCM (non bloccare l'attivazione se fallisce)
                    if (window.Capacitor?.isNativePlatform?.() && typeof registerNativePush === 'function') {
                          try {
                            console.log('[PUSH DEBUG] after activation -> calling registerNativePush');
                            await registerNativePush();
                          } catch (e) {
                            console.warn('Native push registration skipped:', e);
                          }
                      } else {
                          console.log('[PUSH DEBUG] after activation -> NOT calling registerNativePush (isNative=', window.Capacitor?.isNativePlatform ? window.Capacitor.isNativePlatform() : 'n/a', ')');
                      }
                    // Salva anche con prefisso per compatibilità al reload
                    storage.setItem('activationCode', codice);
                    storage.setItem('activationPhone', telefono);

                    // NASCONDI modale e MOSTRA container solo dopo attivazione riuscita
                    const modal = document.getElementById('activation-modal');
                    if (modal) {
                        modal.classList.remove('show');
                        modal.style.display = 'none';
                    }
                    const container = document.querySelector('.container');
                    if (container) container.style.display = 'flex';
                    
                    errorEl.textContent = '✅ Attivazione completata!';
                    errorEl.style.color = '#27ae60';
                    errorEl.classList.add('show');
                    
                    setTimeout(() => {
                        location.reload();
                    }, 1000);
                    
                } else {
                    // Gestione errori dal nuovo sistema
                    attemptsRemaining--;
                    
                    let errorMessage = result.message || 'Errore durante l\'attivazione';
                    
                    if (result.error === 'ALREADY_REGISTERED') {
                        // In muletto: resta demo senza messaggio bloccante
                        if (IS_MULETTO) {
                            console.warn('ALREADY_REGISTERED (demo): nessun blocco, resta muletto');
                            return;
                        }
                        errorMessage = '❌ Codice già in uso su altro dispositivo';
                    } else if (result.error === 'INVALID_CODE') {
                        errorMessage = '❌ Codice non valido';
                    } else if (result.error === 'CODE_DISABLED') {
                        errorMessage = '❌ Codice disattivato';
                    }
                    
                    showActivationError(errorMessage);
                    codeInput.classList.add('error');
                    setTimeout(() => codeInput.classList.remove('error'), 500);
                    
                    if (attemptsRemaining > 0) {
                        attemptsEl.textContent = `Tentativi rimasti: ${attemptsRemaining}/${MAX_ATTEMPTS}`;
                    } else {
                        bloccaApplicazione();
                    }
                }
                
            } catch (error) {
                console.error(' ERRORE CHIAMATA activation.php:', error);
                showActivationError('❌ Errore di connessione. Riprova.');
                console.error(error);
            } finally {
                btn.disabled = false;
                btn.textContent = 'Attiva Applicazione';
            }
        }
        
        function showActivationError(message) {
            const errorEl = document.getElementById('activation-error');
            errorEl.textContent = message;
            errorEl.classList.add('show');
            setTimeout(() => {
                errorEl.classList.remove('show');
            }, 3000);
        }

        // Impedisce chiusura modale attivazione se non c'è codice
        function preventCloseIfNoCode(event) {
            // Se clicca fuori dal contenuto (sullo sfondo), controlla se c'è codice
            if (event.target.id === 'activation-modal') {
                // Solo in app installata: impedisci chiusura se non attivata
                if (IS_STANDALONE) {
                    let codice = storage.getItem('activationCode');
                    let telefono = storage.getItem('activationPhone');
                    
                    // Fallback senza prefisso
                    if (!codice || !telefono) {
                        codice = localStorage.getItem('activationCode');
                        telefono = localStorage.getItem('activationPhone');
                    }
                    
                    // Se non c'è codice, NON chiudere il modale (BLOCCO OBBLIGATORIO)
                    if (!codice || !telefono) {
                        event.preventDefault();
                        event.stopPropagation();
                        console.log('🚫 Modal attivazione NON chiudibile - App non attivata');
                        return false;
                    }
                }
            }
        }
        
        // ===== Modale messaggi cliente =====
        function openMessageModal() {
            const modal = document.getElementById('message-modal');
            const err = document.getElementById('message-error');
            if (err) err.style.display = 'none';
            // Pulisce solo i campi testo, ma ripristina il telefono salvato
            const nameEl = document.getElementById('message-name');
            const phoneEl = document.getElementById('message-phone');
            const textEl = document.getElementById('message-text');
            if (nameEl) nameEl.value = '';
            if (textEl) textEl.value = '';
            if (phoneEl) {
                let tel = localStorage.getItem('userTelefono') || '';
                if (!tel) {
                    try {
                        tel = storage.getItem('activationPhone') || localStorage.getItem('activationPhone') || '';
                    } catch (_) {}
                }
                phoneEl.value = tel;
            }
            if (modal) {
                modal.style.display = 'flex';
            }
        }

        function closeMessageModal(event) {
            if (event && event.target && event.target.id === 'message-modal') {
                document.getElementById('message-modal').style.display = 'none';
                return;
            }
            if (!event || !event.target || event.target.id === 'message-modal' || event.target.closest('.message-modal-content') === null) {
                const modal = document.getElementById('message-modal');
                if (modal) modal.style.display = 'none';
            } else {
                const modal = document.getElementById('message-modal');
                if (modal) modal.style.display = 'none';
            }
        }

        const API_BASE = (function() {
            try {
                // Se siamo in file:// (app native) usa dominio assoluto
                if (location.origin === 'null' || location.origin === '' || location.protocol === 'file:') {
                    return 'https://puschpromozioni.it/ristorantemimmo1/PRENOTAZIONI/';
                }
                // Se siamo già sul dominio corretto, usa relativo
                if (location.hostname && location.pathname.includes('/ristorantemimmo1/PRENOTAZIONI')) {
                    return '';
                }
            } catch (_) {}
            // Fallback assoluto
            return 'https://puschpromozioni.it/ristorantemimmo1/PRENOTAZIONI/';
        })();

        async function submitMessage(ev) {
            if (ev) ev.preventDefault();
            const nameEl = document.getElementById('message-name');
            const phoneEl = document.getElementById('message-phone');
            const textEl = document.getElementById('message-text');
            const errEl = document.getElementById('message-error');
            const nome = nameEl ? nameEl.value.trim() : '';
            const telefono = phoneEl ? phoneEl.value.trim() : '';
            const testo = textEl ? textEl.value.trim() : '';
            if (!nome || !telefono || !testo) {
                if (errEl) {
                    errEl.textContent = 'Compila tutti i campi';
                    errEl.style.display = 'block';
                }
                return;
            }
            if (errEl) errEl.style.display = 'none';
            try { localStorage.setItem('userTelefono', telefono); } catch (_) {}
            try {
                const res = await fetch(API_BASE + 'api.php?action=message', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        tipo: 'messaggio',
                        nome_cliente: nome,
                        telefono_cliente: telefono,
                        testo_messaggio: testo
                    })
                });
                const result = await res.json();
                if (!res.ok || result.success === false) {
                    if (errEl) {
                        errEl.textContent = result.message || 'Errore invio messaggio';
                        errEl.style.display = 'block';
                    }
                } else {
                    if (typeof showCustomAlert === 'function') {
                        showCustomAlert('Messaggio inviato', false);
                    }
                    closeMessageModal();
                }
            } catch (error) {
                if (errEl) {
                    errEl.textContent = 'Errore di rete';
                    errEl.style.display = 'block';
                }
                console.error('submitMessage error:', error);
            }
        }

        
        function openActivationModalFromPopup() {
            const info = document.getElementById('infoModal');
            if (info) info.style.display = 'none';
            console.log(' STEP 1 - Funzione chiamata');
            const modal = document.getElementById('activation-modal');
            console.log(' STEP 2 - Modal trovato?', modal);
        if (modal) {
            modal.classList.add('show');
            modal.style.display = 'flex';
            console.log(' STEP 3b - modal computedStyle display:', window.getComputedStyle(modal).display);
            console.log(' STEP 3c - modal computedStyle zIndex:', window.getComputedStyle(modal).zIndex);
            console.log(' STEP 3d - modal getBoundingClientRect:', JSON.stringify(modal.getBoundingClientRect()));
            console.log(' STEP 3 - Classe show aggiunta. Classi:', modal.className);
        } else {
            console.log(' ERRORE - activation-modal NON ESISTE!');
            }
            const warningEl = document.getElementById('activation-install-warning');
            if (warningEl) warningEl.style.display = 'none';
            const form = modal ? modal.querySelector('.activation-form') : null;
            console.log(' STEP 4 - form trovato?', form);
            if (form) form.querySelectorAll('input, button').forEach(el => el.disabled = false);
            console.log(' STEP 5 - FINE funzione');
        }
function openActivationModalManual() {
            // DEBUG: Verifica valore IS_MULETTO
            console.log('🔍 DEBUG openActivationModalManual - IS_MULETTO:', IS_MULETTO, 'IS_STANDALONE:', IS_STANDALONE);
            
            // BLOCCA completamente in modalità browser
            if (IS_MULETTO && !FORCE_BROWSER_MODE) {
                console.log('✅ BLOCCO ATTIVO - Mostro alert');
                showCustomAlert('⚠️ INSTALLA PRIMA L\'APP!\n\nIl codice di attivazione può essere inserito solo dopo aver installato l\'app sul telefono.\n\nVai su "ISTRUZIONI UTILIZZO" per installare l\'app.', true);
                return;
            }
            
            console.log('⚠️ BLOCCO NON ATTIVO - Continuo con apertura modal');
            
        const code = storage.getItem('activationCode');
        
        // Se già attivata, mostra messaggio
        if (code && IS_STANDALONE) {
            showCustomAlert('✅ Applicazione già attivata', false);
                return;
            }
        
        // Apri modale
        const modal = document.getElementById('activation-modal');
        modal.classList.add('show');
        modal.style.display = 'flex';
            
            // Se non installata, mostra warning e disabilita form (solo se blocco attivo)
            if (IS_MULETTO && !FORCE_BROWSER_MODE) {
                const warningEl = document.getElementById('activation-install-warning');
                if (warningEl) {
                    warningEl.style.display = 'block';
                }
                // Disabilita form
                const form = modal.querySelector('.activation-form');
                if (form) {
                    form.querySelectorAll('input, button').forEach(el => el.disabled = true);
                }
            } else {
                const warningEl = document.getElementById('activation-install-warning');
                if (warningEl) {
                    warningEl.style.display = 'none';
                }
                // Abilita form
                const form = modal.querySelector('.activation-form');
                if (form) {
                    form.querySelectorAll('input, button').forEach(el => el.disabled = false);
                }
            }
        }
        // ===== PATTERN DISPATCHER =====
const ActionDispatcher = {
    handlers: {},
    
    register(actionName, handler) {
        this.handlers[actionName] = handler;
        console.log('✅ Registrata azione:', actionName);
    },
    
    dispatch(actionName, ...args) {
        if (this.handlers[actionName]) {
            console.log('🚀 Dispatch azione:', actionName);
            return this.handlers[actionName](...args);
        }
        console.error('❌ Azione non trovata:', actionName);
        return null;
    }
};

ActionDispatcher.register('openWelcomeModal', openWelcomeModal);
ActionDispatcher.register('closePromoModal', closePromoModal);
    
        // ===== CONFIGURAZIONE =====
        const workingHours = {
            start: '08:00',
            end: '20:30',
            interval: 30
        };

        let currentDate = new Date();

        let currentSelectedDate = null;
        let currentSelectedTime = null;
        
        let myAppointmentsList = [];
        let currentAppointmentIndex = 0;
        
        let globalAppointmentsCache = [];
        let isModalOpen = false;
        
        let notifiedAppointments = new Set(JSON.parse(localStorage.getItem('notifiedAppointments') || '[]'));
        let isFirstLoad = true;
        let popupQueue = [];
        let isShowingPopup = false;
        let currentPopupId = null;
          
        function addToPopupQueue(id, message, isError) {
            const item = { id, message, isError: !!isError };
            popupQueue.push(item);
            if (!isShowingPopup) {
                showNextPopup();
            }
        }

        function showNextPopup() {
            if (popupQueue.length === 0) {
                isShowingPopup = false;
                currentPopupId = null;
                return;
            }
            
            isShowingPopup = true;
            const popup = popupQueue.shift();
            currentPopupId = popup.id;
            
            showCustomAlert(popup.message, popup.isError);
            // Nessuna auto‑chiusura qui: si chiude solo con la X (closeAlert)
        }
        // ===== POLLING GLOBALE =====
        let refreshInProgress = false;
        let refreshFailCount = 0;
        async function refreshGlobalAppointments() {
            if (refreshInProgress) {
                console.warn('⚠️ refresh già in corso, salto.');
                return;
            }
            try {
                refreshInProgress = true;
                console.log('🔄 Aggiornamento appuntamenti in corso...');
                const response = await fetch('api.php');

                
                if (!response.ok) {
                    console.error('❌ Errore HTTP:', response.status, response.statusText);
                    refreshFailCount++;
                    if (refreshFailCount >= 3) {
                        console.warn('⚠️ Troppi errori consecutivi, stop polling temporaneo.');
                        return;
                    }
                    return;
                }
                
                refreshFailCount = 0;
                globalAppointmentsCache = await response.json();
                console.log('✅ Appuntamenti caricati:', globalAppointmentsCache.length, 'totali');
                
                // Mostra appuntamenti per debug
                if (globalAppointmentsCache.length > 0) {
                    console.log('📅 Primi 3 appuntamenti:', globalAppointmentsCache.slice(0, 3).map(a => ({
                        data: (a && a.start) ? a.start.substring(0, 10) : '',
                        ora: (a && a.start) ? a.start.substring(11, 16) : '',
                        cliente: (a && a.extendedProps && a.extendedProps.cliente) || (a && a.title) || ''
                    })));
                }
                
                checkForConfirmedAppointments();
                
                if (isModalOpen && currentSelectedDate) {
                    await loadSlots(currentSelectedDate, document.getElementById('availableSlotsList'));
                }
                
            } catch (error) {
                console.error('❌ ERRORE polling globale:', error);
                console.error('❌ Tipo errore:', error.name);
                console.error('❌ Messaggio:', error.message);
                refreshFailCount++;
                if (refreshFailCount >= 3) {
                    console.warn('⚠️ Troppi errori consecutivi, stop polling temporaneo.');
                    return;
                }
            } finally {
                refreshInProgress = false;
            }
        }
        function checkForConfirmedAppointments() {
            const stored = JSON.parse(localStorage.getItem('myAppointmentIds') || '[]');
            const awaiting = Array.isArray(stored) ? stored.filter(a => a && a.awaitingConfirmation) : [];
            const myIds = awaiting.map(a => parseInt(a.id, 10)).filter(Boolean);
            if (myIds.length === 0) return;

            const shownPopups = JSON.parse(localStorage.getItem('shownPopups') || '[]');
        const cache = Array.isArray(globalAppointmentsCache) ? globalAppointmentsCache : [];

            const mieConfermati = cache.filter(appt => {
                const id = parseInt(appt && appt.id, 10);
                const status = appt && appt.extendedProps ? appt.extendedProps.status : undefined;
                return myIds.includes(id) && (status === 'confirmed' || status === 'confermato');
            });

            if (mieConfermati.length === 0) return;

            // Costruisci mappa per aggiornare awaitingConfirmation
            const awaitingMap = new Map(awaiting.map(a => [parseInt(a.id,10), a]));
            const updated = [...stored];

            mieConfermati.forEach(appt => {
                const popupId = 'confirm_' + appt.id;
                if (!shownPopups.includes(popupId)) {
                    const date = new Date(appt.start);
                    const dateStr = date.toLocaleDateString('it-IT', { weekday: 'long', day: 'numeric', month: 'long' });
                    const timeStr = date.toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' });

                    const nome = (appt && appt.extendedProps && (appt.extendedProps.cliente || appt.extendedProps.customer_name)) || '';
                    const primoNome = (nome.split(' ')[0]) || '';
                    const animale = (appt && appt.extendedProps && appt.extendedProps.pet_name) || '';

                    let messaggio = `Ciao ${primoNome}! ✅ Confermato il tuo appuntamento`;
                    if (animale) messaggio += ` con ${animale}`;
                    messaggio += ` per ${dateStr} alle ${timeStr}. Ti aspettiamo!`;

                    // Suono conferma appuntamento 🔊 (TEMPORANEAMENTE DISATTIVATO)
                    /* try {
                        const audioContext = new (window.AudioContext || window.webkitAudioContext)();
                        const osc1 = audioContext.createOscillator();
                        const gain1 = audioContext.createGain();
                        osc1.connect(gain1);
                        gain1.connect(audioContext.destination);
                        osc1.frequency.value = 1200;
                        gain1.gain.value = 0.4;
                        osc1.start(audioContext.currentTime);
                        osc1.stop(audioContext.currentTime + 0.15);
                        
                        const osc2 = audioContext.createOscillator();
                        const gain2 = audioContext.createGain();
                        osc2.connect(gain2);
                        gain2.connect(audioContext.destination);
                        osc2.frequency.value = 900;
                        gain2.gain.value = 0.4;
                        osc2.start(audioContext.currentTime + 0.2);
                        osc2.stop(audioContext.currentTime + 0.4);
                        
                        console.log('🔊 Suono conferma riprodotto!');
                    } catch (e) {
                        console.log('⚠️ Suono conferma non riproducibile:', e);
                    } */

                    addToPopupQueue(popupId, messaggio, false);

                    // segna come mostrato e disattiva awaiting
                    shownPopups.push(popupId);
                    const entry = awaitingMap.get(parseInt(appt.id,10));
                    if (entry) entry.awaitingConfirmation = false;
                }
            });

            localStorage.setItem('shownPopups', JSON.stringify(shownPopups));
            localStorage.setItem('myAppointmentIds', JSON.stringify(updated.map(it => {
                const e = awaitingMap.get(parseInt(it.id,10));
                return e ? { ...it, awaitingConfirmation: e.awaitingConfirmation } : it;
            })));
        }
       
        // ========================================
        // ⚠️ ATTENZIONE AI/ASSISTENTI: NON MODIFICARE QUESTA SEZIONE!
        // Se stai fixando qualcosa in altra parte del file e pensi che
        // la soluzione coinvolga il codice qui sotto, FERMATI.
        // Non toccare niente. Scrivi solo:
        // "ATTENZIONE: questa modifica tocca il calendario. Non procedo."
        // Poi aspetta conferma esplicita prima di fare qualcosa.
        // Funzioni protette: updateCalendar, renderCustomGrid,
        // showSlotList, loadSlots, getAvailableSlots, parseTime,
        // formatTime, refreshGlobalAppointments, workingHours.
        // ⚠️ CRITICAL CALENDAR FUNCTIONS - DO NOT EDIT OR REMOVE!
        // ⚠️ Queste funzioni sono ESSENZIALI per il funzionamento del calendario
        // ⚠️ Qualsiasi modifica romperà il sistema di prenotazione
        // ========================================
        
        setInterval(refreshGlobalAppointments, 8000);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshGlobalAppointments(); }); 
        function updateCalendar() {
            const firstDayOfMonth = new Date(currentDate.getFullYear(), currentDate.getMonth(), 1);
            const monthFormatter = new Intl.DateTimeFormat('it-IT', { month: 'long', year: 'numeric' });
            document.getElementById('current-month-year').textContent = monthFormatter.format(currentDate);
            renderCustomGrid(firstDayOfMonth);
        }

        function renderCustomGrid(firstDayOfMonth) {
               const calendarContainer = document.getElementById('calendar-container');
if (!calendarContainer) { console.warn('calendar-container mancante'); return; }
try {
    calendarContainer.innerHTML = '';
} catch (e) {
    console.error('reset calendario fallito:', e);
    return;
}
// Ripristina eventuali stili inline imposti in precedenza (per tornare a 2 righe da CSS)
calendarContainer.style.gridTemplateColumns = '';
calendarContainer.style.width = '';

            // Calcola numero giorni reali del mese
            const year = firstDayOfMonth.getFullYear();
            const month = firstDayOfMonth.getMonth();
            const daysInMonth = new Date(year, month + 1, 0).getDate();

            // Costruzione caselle dei soli giorni del mese corrente
            const fragment = document.createDocumentFragment();
            for (let i = 0; i < daysInMonth; i++) {
                const day = new Date(year, month, 1 + i);
                const dayBox = document.createElement('div');
                dayBox.className = 'day-box available';
                dayBox.innerHTML = `${day.getDate()}`;

                const yyyy = day.getFullYear();
                const mm = String(day.getMonth() + 1).padStart(2, '0');
                const dd = String(day.getDate()).padStart(2, '0');
                const dateLocal = `${yyyy}-${mm}-${dd}`;
                dayBox.setAttribute('data-date', dateLocal);

                dayBox.onclick = function() {
                    document.querySelectorAll('.day-box').forEach(el => el.classList.remove('selected'));
                    this.classList.add('selected');
                    showSlotList(this.getAttribute('data-date'));
                };

                fragment.appendChild(dayBox);
            }
            calendarContainer.appendChild(fragment);

            // Disposizione esatta su 2 righe: colonne = ceil(giorni/2)
            const cols = Math.ceil(daysInMonth / 2);
            const sample = calendarContainer.querySelector('.day-box');
            const boxW = sample ? sample.offsetWidth : 60;
            if (cols > 0 && boxW > 0) {
                calendarContainer.style.gridTemplateColumns = `repeat(${cols}, ${boxW}px)`;
                calendarContainer.style.width = 'max-content';
            }
        }
        
        // ========================================
        // ⚠️ FINE SEZIONE CRITICA CALENDARIO - DO NOT EDIT ABOVE!
        // ========================================
        
        async function showSlotList(dateStr) {
            // Blocca se non installata (muletto/browser)
            if (IS_MULETTO && !FORCE_BROWSER_MODE) {
                showCustomAlert('⚠️ INSTALLA PRIMA L\'APP!\n\nLe prenotazioni sono disponibili solo dopo aver installato l\'app sul telefono.\n\nVai su "ISTRUZIONI UTILIZZO" per installare l\'app.', true);
                return;
            }
            
            currentSelectedDate = dateStr;
            isModalOpen = true;
            
            document.getElementById('modalTitle').textContent = `Orari disponibili per il ${new Date(dateStr).toLocaleDateString('it-IT', { weekday: 'short', day: 'numeric', month: 'long' })}`;
            
            const listEl = document.getElementById('availableSlotsList');
            
            document.getElementById('bookingForm').style.display = 'none';
            listEl.style.display = 'block';

            await loadSlots(dateStr, listEl);

            document.getElementById('slotModal').style.display = 'flex';
        }
        
        async function loadSlots(dateStr, listEl) {
            try {
                console.log('🔍 Caricamento slot per data:', dateStr);
                const appointments = globalAppointmentsCache;
                console.log('📦 Appuntamenti totali in cache:', appointments.length);
                
                const oggi = new Date();
                oggi.setHours(0, 0, 0, 0);
                
                // Filtra appuntamenti per la data selezionata
                const apptsDelGiorno = appointments.filter(appt => {
                    if (!appt.start) return false;
                    return appt.start.startsWith(dateStr);
                });
                
                console.log('📅 Appuntamenti trovati per', dateStr, ':', apptsDelGiorno.length);
                if (apptsDelGiorno.length > 0) {
                    console.log('🕐 Orari occupati:', apptsDelGiorno.map(a => a.start.substring(11, 16)));
                }
                
                const occupiedSlots = appointments
                    .filter(appt => {
                        if (!appt.start) return false;
                        return appt.start.startsWith(dateStr);
                    })
                    .map(appt => appt.start.substring(11, 16));
                
                console.log('🔒 Slot occupati estratti:', occupiedSlots);
                
                const slots = getAvailableSlots(dateStr, occupiedSlots);
                
                listEl.innerHTML = '';
                
                if (slots.free.length === 0 && slots.occupied.length === 0) {
                    listEl.innerHTML = '<p style="text-align:center;">Nessun orario disponibile.</p>';
                } else {
                    const allSlots = [];
                    
                    slots.free.forEach(slot => {
                        allSlots.push({ time: slot, available: true });
                    });
                    
                    slots.occupied.forEach(slot => {
                        allSlots.push({ time: slot, available: false });
                    });
                    
                    allSlots.sort((a, b) => a.time.localeCompare(b.time));
                    
                    allSlots.forEach(slot => {
                        const slotDiv = document.createElement('div');
                        
                        if (slot.available) {
                            slotDiv.className = 'slot-time';
                            slotDiv.textContent = slot.time + ' - Disponibile';
                            slotDiv.onclick = function() {
                                currentSelectedTime = slot.time;
                                showBookingForm();
                            };
                        } else {
                            slotDiv.className = 'slot-time occupied';
                            slotDiv.textContent = slot.time + ' - Occupato';
                        }
                        
                        listEl.appendChild(slotDiv);
                    });
                }
                
            } catch (error) {
                console.error('Errore nel caricamento slot:', error);
                listEl.innerHTML = '<p style="text-align:center;">Errore nel caricamento. Riprova.</p>';
            }
        }
        
        function showBookingForm() {
            document.getElementById('availableSlotsList').style.display = 'none';
            document.getElementById('modalTitle').textContent = `Conferma la Prenotazione`;
            
            const formattedDate = new Date(currentSelectedDate).toLocaleDateString('it-IT', { weekday: 'long', day: 'numeric', month: 'long' });
            document.getElementById('fixedTimeInfo').textContent = `${formattedDate} alle ${currentSelectedTime}`;
            document.getElementById('selectedDateTime').value = `${currentSelectedDate}T${currentSelectedTime}`;
            
            loadUserData();
            document.getElementById('bookingForm').style.display = 'block';
            const nomeField = document.getElementById('inputNomeCompleto');
            if (nomeField) nomeField.focus();
        }

        function saveUserData(nomeCompleto, telefono) {
            if (typeof(Storage) !== "undefined") {
                localStorage.setItem("userNomeCompleto", nomeCompleto);
                localStorage.setItem("userTelefono", telefono);
            }
        }

        function loadUserData() {
            if (typeof(Storage) === "undefined") return;
            const nomeSalvato = localStorage.getItem("userNomeCompleto") || '';
            let telefonoSalvato = localStorage.getItem("userTelefono") || '';
            if (!telefonoSalvato) {
                try {
                    telefonoSalvato = storage.getItem('activationPhone') || localStorage.getItem('activationPhone') || '';
                } catch (_) {}
            }
            document.getElementById('inputNomeCompleto').value = nomeSalvato;
            document.getElementById('inputTelefono').value = telefonoSalvato;
        }

        async function addAppointment() {
            await ensurePushSubscription();
            // Blocca se non installata (muletto/browser)
            if (IS_MULETTO && !FORCE_BROWSER_MODE) {
                showCustomAlert('⚠️ INSTALLA PRIMA L\'APP!\n\nQuesta funzione è disponibile solo dopo aver installato l\'app sul telefono.\n\nVai su "ISTRUZIONI UTILIZZO" per installare l\'app.', true);
                closeSlotModal();
                return;
            }
            
            const datetime = document.getElementById('selectedDateTime').value;
            const nomeCompleto = document.getElementById('inputNomeCompleto').value;
            const telefono = document.getElementById('inputTelefono').value; 
            const problematica = document.getElementById('inputProblematica').value;

            const [dateStr, timeStr] = datetime.split('T');
            let activationCode = '';
            try {
                if (typeof getActivationData === 'function') {
                    activationCode = (getActivationData().code || '');
                }
                if (!activationCode) {
                    activationCode = localStorage.getItem('activationCode') || storage.getItem('activationCode') || '';
                }
            } catch (_) {}
            try {
                await refreshGlobalAppointments();
                
                const slotOccupato = globalAppointmentsCache.some(appt => {
                    if (!appt.start) return false;
                    const apptDate = appt.start.substring(0, 10);
                    const apptTime = appt.start.substring(11, 16);
                    return apptDate === dateStr && apptTime === timeStr;
                });
                
                if (slotOccupato) {
                    showCustomAlert('Questo orario è stato appena prenotato. Scegli un altro orario.', true);
                    document.getElementById('bookingForm').style.display = 'none';
                    document.getElementById('availableSlotsList').style.display = 'block';
                    document.getElementById('modalTitle').textContent = `Orari disponibili per il ${new Date(dateStr).toLocaleDateString('it-IT', { weekday: 'short', day: 'numeric', month: 'long' })}`;
                    await loadSlots(dateStr, document.getElementById('availableSlotsList'));
                    return;
                }
                
                const appuntamentiDelGiorno = globalAppointmentsCache.filter(appt => {
                    if (!appt.start) return false;
                    return appt.start.startsWith(dateStr);
                }).length;
                
                if (appuntamentiDelGiorno >= 15) {
                    showCustomAlert('Giorno completo. Il servizio ha già 15 appuntamenti. Scegli un altro giorno.', true);
                    closeSlotModal();
                    return;
                }
                
                saveUserData(nomeCompleto, telefono);

               const appointmentData = {
                    customer_name: nomeCompleto,
                    title: nomeCompleto,
                    cliente: nomeCompleto,
                    appointment_date: dateStr,
                    appointment_time: timeStr,
                    telefono: telefono,
                    activation_code: activationCode,
                    note: problematica,
                    status: 'pending',
                    source: 'website_booking',
                    timestamp: new Date().toISOString()
                };


                    const response = await fetch(API_BASE + 'api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(appointmentData)
                });

                const result = await response.json();
                
                if (result.status === 'saved') {
                    showCustomAlert('Appuntamento prenotato per il ' + dateStr + ' alle ' + timeStr + '. Riceverai conferma.', false);
                    document.getElementById('inputProblematica').value = '';
                    const myAppointments = JSON.parse(localStorage.getItem('myAppointmentIds') || '[]');
                    myAppointments.push({
                        id: parseInt(result.id),
                        telefono: telefono,
                        pet_name: '',
                        awaitingConfirmation: true,
                        createdAt: new Date().toISOString()
                    });
                    localStorage.setItem('myAppointmentIds', JSON.stringify(myAppointments));
                    const dataVisualizza = new Date(dateStr + 'T' + timeStr);
                    const dataStr = dataVisualizza.getDate() + ' ' + dataVisualizza.toLocaleDateString('it-IT', { month: 'short' });
                    const appointmentInfo = document.getElementById('appointmentInfo');
                    const myAppointmentsEl = document.getElementById('myAppointments');
                    if (appointmentInfo) {
                        appointmentInfo.textContent = dataStr + ' ' + timeStr.substring(0, 5);
                    }
                    if (myAppointmentsEl) {
                        myAppointmentsEl.classList.add('visible');
                    }
                    
                    closeSlotModal();
                    updateCalendar();
                } else {
                    showCustomAlert('Errore nel salvataggio. Riprova.', true);
                }
                
            } catch (error) {
                showCustomAlert('Errore di connessione. Riprova.', true);
                console.error('Errore:', error);
            }
        }

        function showCustomAlert(message, isError = false) {
            const alertEl = document.getElementById('custom-alert');
            document.getElementById('alert-message').textContent = message;
            
            if (isError) {
                alertEl.classList.add('error');
            } else {
                alertEl.classList.remove('error');
            }
            
            alertEl.classList.add('show');
            
            if (isError) {
                setTimeout(() => {
                    alertEl.classList.remove('show');
                }, 5000);
            }
        }
        
        function closeAlert() {
            const alertEl = document.getElementById('custom-alert');
            alertEl.classList.remove('show');

            if (typeof currentPopupId !== 'undefined' && currentPopupId !== null) {
                const shownPopups = JSON.parse(localStorage.getItem('shownPopups') || '[]');
                if (!shownPopups.includes(currentPopupId)) {
                    shownPopups.push(currentPopupId);
                    localStorage.setItem('shownPopups', JSON.stringify(shownPopups));
                }
            }
            // prepara al prossimo popup
            currentPopupId = null;
            isShowingPopup = false;
            setTimeout(showNextPopup, 150);
        }

        function getAvailableSlots(dateStr, occupiedSlots = []) {
            const free = [];
            const occupied = [];
            
            let start = parseTime(workingHours.start);
            const end = parseTime(workingHours.end);

            while (start < end) {
                const slotTime = formatTime(start);
                
                if (occupiedSlots.includes(slotTime)) {
                    occupied.push(slotTime);
                } else {
                    free.push(slotTime);
                }

                start += workingHours.interval;
            }
            
            return { free, occupied };
        }

        function closeSlotModal(event) {
            if (!event || event.target.id === 'slotModal' || event.target.id === 'closeButton') {
                document.getElementById('slotModal').style.display = 'none';
                document.querySelectorAll('.day-box').forEach(el => el.classList.remove('selected'));
                
                document.getElementById('availableSlotsList').style.display = 'block';
                document.getElementById('bookingForm').style.display = 'none';
                
                isModalOpen = false;
            }
        }
        
        function changeMonth(delta) {
            currentDate.setMonth(currentDate.getMonth() + delta);
            currentDate.setDate(1); 
            updateCalendar();
        }
        
        document.addEventListener('DOMContentLoaded', () => {
            try {
                updateCalendar();
            } catch (e) {
                console.error('updateCalendar init error:', e);
            }
        });
        
        // =====================
        // Modale Info/Install
        // =====================
        let deferredInstallPrompt = null;
        const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
        const isInStandaloneMode = window.navigator.standalone === true;

        // Intercetta beforeinstallprompt (Android/Chrome)
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredInstallPrompt = e;
            updateInstallButtonState(); // Aggiorna stato pulsante quando disponibile
        });

        // Salva installedAt su iOS al primo avvio standalone
        if (isIOS && isInStandaloneMode) {
            try {
                const installed = localStorage.getItem('installedAt');
                if (!installed) {
                    localStorage.setItem('installedAt', new Date().toISOString());
                }
            } catch (_) {}
        }


        function setPrivacyCookie(val) {
  var v = val ? 'true' : 'false';
  var maxAge = 60 * 60 * 24 * 365; // 1 anno

  // 1) condiviso su www + non-www
  document.cookie = 'privacyAccepted=' + v + '; Max-Age=' + maxAge + '; Path=/; Domain=.puschpromozioni.it; SameSite=Lax; Secure';

  // 2) fallback host-only
  document.cookie = 'privacyAccepted=' + v + '; Max-Age=' + maxAge + '; Path=/; SameSite=Lax';
}

        function getCookie(name) {
  try {
    var esc = name.replace(/([.$?*|{}()[\]\\\/\+^])/g, '\\$1');
    var m = document.cookie.match(new RegExp('(?:^|; )' + esc + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : null;
  } catch (_) {
    return null;
  }
}

        function getPrivacyAccepted() {
            // compat: usi 2 chiavi già presenti + cookie
            const ls = localStorage.getItem('privacyAccepted') === 'true';
            const ck = getCookie('privacyAccepted') === 'true';
            return ls || ck;
        }

        function openInfoModal() {
            try {
                const accepted = getPrivacyAccepted();
                document.getElementById('infoModal').style.display = 'flex';
                setTimeout(function() {
                    const cb = document.getElementById('privacyAccept');
                    if (cb) {
                        if (accepted) {
                            cb.checked = true;
                            cb.style.pointerEvents = 'none';
                        } else {
                            cb.checked = false;
                            cb.style.pointerEvents = 'auto';
                        }
                    }
                    updateInstallButtonState(); // aggiorna i pulsanti in base allo stato privacy
                }, 50);
            } catch (_) {
                document.getElementById('infoModal').style.display = 'flex';
            }
        }

        function closeInfoModal(event) {
            if (!event || event.target.id === 'infoModal' || event.target.id === 'closeInfo') {
                document.getElementById('infoModal').style.display = 'none';
            }
        }

        function updateInstallButtonState() {
            const btn = document.getElementById('installBtn');
            const voiceBtn = document.getElementById('installVoiceBtn');
            if (!btn && !voiceBtn) return;
        const privacyChk = document.getElementById('privacyAccept');
        const accepted = (privacyChk && privacyChk.checked) || getPrivacyAccepted();
            
            // Se già installata, disabilita pulsante principale
            if (IS_STANDALONE) {
                if (btn) {
                    btn.disabled = true;
                    btn.textContent = '✅ App già installata';
                }
                if (voiceBtn) {
                    voiceBtn.disabled = !VOICE_APK_URL;
                }
                return;
            }
            
            // Se iOS, mostra istruzioni invece di pulsante normale
            if (isIOS && !isInStandaloneMode) {
                if (btn) btn.textContent = '📱 Mostra istruzioni iOS';
            } else if (deferredInstallPrompt) {
                if (btn) btn.textContent = '📥 Installa applicazione';
            } else {
                if (btn) btn.textContent = 'Installa applicazione';
            }
            
            if (btn) btn.disabled = !accepted;

            if (voiceBtn) {
                if (accepted && VOICE_APK_URL) {
                    voiceBtn.disabled = false;
                    voiceBtn.textContent = 'INSTALLA AGENDA VOCALE GRATIS';
                } else {
                    voiceBtn.disabled = true;
                    voiceBtn.textContent = 'INSTALLA AGENDA VOCALE GRATIS';
                }
            }
        }

        function onPrivacyChange(e) {
            const checked = !!(e && e.target && e.target.checked);
            localStorage.setItem('privacyAccepted', checked ? 'true' : 'false');
            setPrivacyCookie(checked);
            updateInstallButtonState();
        }

        function acceptPrivacyAndPersist() {
  try {
    localStorage.setItem('privacyAccepted', 'true');
  } catch (_) {}
  try { if (typeof setPrivacyCookie === 'function') setPrivacyCookie(true); } catch (_) {}
  try {
    const cb = document.getElementById('privacyAccept');
    if (cb) cb.checked = true;
  } catch (_) {}
  try { if (typeof updateInstallButtonState === 'function') updateInstallButtonState(); } catch (_) {}
  try {
            console.log('[PRIVACY] saved ->', localStorage.getItem('privacyAccepted'),
          document.cookie);
  } catch (_) {}
}

        function openPrivacy(event) {
            if (event) event.preventDefault();
            document.getElementById('privacyModal').style.display = 'flex';
        }

        function chiudiVista() {
            const params = new URLSearchParams(window.location.search);
            if (params.get('fromPush') === '1') {
                navigaRobusta('agenda-cliente-toilet-001.html');
            } else {
                history.back();
            }
        }

        function acceptPrivacy() {
  acceptPrivacyAndPersist();
  document.getElementById('privacyModal').style.display = 'none';
  showCustomAlert('✅ Privacy accettata', false);
}

        function parseTime(timeStr) {
            const [hours, minutes] = (timeStr || '').split(':').map(Number);
            if (Number.isNaN(hours) || Number.isNaN(minutes)) return 0;
            return hours * 60 + minutes;
        }

        function formatTime(minutes) {
            const h = Math.floor(minutes / 60);
            const m = minutes % 60;
            return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
        }

        async function handleInstallClick() {
        const privacyChk = document.getElementById('privacyAccept');
        const accepted = (privacyChk && privacyChk.checked) || getPrivacyAccepted();
            if (!accepted) return;
            
            // iOS: mostra istruzioni
            if (isIOS && !isInStandaloneMode) {
                showIOSInstallInstructions();
                return;
            }
            
            // Android/Chrome: usa deferredPrompt
            if (deferredInstallPrompt) {
                deferredInstallPrompt.prompt();
                try {
                    const choiceResult = await deferredInstallPrompt.userChoice;
                    if (choiceResult.outcome === 'accepted') {
                        console.log('✅ Utente ha accettato l\'installazione');
                    }
                } finally {
                    deferredInstallPrompt = null;
                    updateInstallButtonState();
                }
            } else {
                // Se non c'è il prompt, mostra comunque istruzioni
                showIOSInstallInstructions();
            }
        }

        function handleInstallVoiceClick() {
        const privacyChk2 = document.getElementById('privacyAccept');
        const accepted = (privacyChk2 && privacyChk2.checked) || getPrivacyAccepted();
            if (!accepted) return;
            if (!VOICE_APK_URL) {
                showCustomAlert('Link APK agenda vocale non configurato.', true);
                return;
            }
            try {
                window.open(VOICE_APK_URL, '_blank');
            } catch (_) {
                showCustomAlert('Errore apertura download agenda vocale.', true);
            }
        }

        function mostraPromozioneModal(promo, orario = null){
            try {
                if (shouldBlockAutoPopup()) {
                    console.log('⛔ Popup automatici bloccati durante visualizzazione PUSH.');
                    return;
                }
                console.log('🎨 Creo modal per promozione:', promo, 'Orario:', orario);
                let pm=document.getElementById('promo-modal');
                if(!pm){
                    console.log('📦 Creo nuovo elemento modal');
                    pm=document.createElement('div');
                    pm.id='promo-modal';
                    pm.style.cssText='display:flex;position:fixed;z-index:10000;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,0.6);justify-content:center;align-items:center';
                    document.body.appendChild(pm);
                } else {
                    console.log('♻️ Riuso modal esistente');
                }
                const di=new Date(promo.data_inizio).toLocaleDateString('it-IT');
                const df=new Date(promo.data_fine).toLocaleDateString('it-IT');
                console.log('📅 Date formattate - Inizio:', di, 'Fine:', df);
                
                const orarioHtml = orario ? `<div style="background:#FFF3E0;padding:8px;border-radius:5px;margin-top:10px;font-weight:bold;color:#E65100">⏰ Orario: ${orario}</div>` : '';
                const orarioPushHtml = promo.push_attivo == 1 && !orario ? `<div style="background:#E3F2FD;padding:10px;border-radius:5px;margin-top:10px;font-weight:bold;color:#1976D2;text-align:center">💬 Pop-up mostrato alle ore: ${new Date().toLocaleTimeString('it-IT', {hour:'2-digit', minute:'2-digit'})}</div>` : '';
                pm.innerHTML = `
                    <div style="background:linear-gradient(135deg,#667eea,#764ba2);border-radius:15px;width:90%;max-width:500px;overflow:hidden;box-shadow:0 10px 40px rgba(0,0,0,0.3)">
                        <div style="background:rgba(255,255,255,0.15);padding:25px;text-align:center;color:white;border-bottom:2px solid rgba(255,255,255,0.2)">
                         <h2 style="margin:0;font-size:28px">💬🐩 ${promo.push_attivo == 1 ? promo.titolo : 'POP-UP: ' + promo.titolo}</h2>  
                        </div>
                        <div style="padding:30px;background:white;color:#333">
                            <p style="font-size:18px;line-height:1.6;margin-bottom:20px">${promo.messaggio}</p>
                           ${orarioHtml}${orarioPushHtml}
                            <div style="display:flex;justify-content:space-around;padding:15px;background:#f8f9fa;border-radius:8px;margin-bottom:20px">
                                <div style="text-align:center">
                                    <div style="font-size:12px;color:#777;text-transform:uppercase">Valida dal</div>
                                    <div style="font-size:16px;font-weight:bold;color:#667eea;margin-top:5px">${di}</div>
                                </div>
                                <div style="text-align:center">
                                    <div style="font-size:12px;color:#777;text-transform:uppercase">Fino al</div>
                                    <div style="font-size:16px;font-weight:bold;color:#667eea;margin-top:5px">${df}</div>
                                </div>
                            </div>
                            <button onclick="ActionDispatcher.dispatch('closePromoModal')" 
                                    style="background:#667eea;color:white;padding:15px 40px;border:none;border-radius:8px;font-size:18px;font-weight:bold;cursor:pointer;width:100%;transition:all 0.3s">
                                Ho capito!
                            </button>
                        </div>
                    </div>
                `;
                
                // Salva l'orario DOPO aver impostato innerHTML (per evitare che venga perso)
                if(orario) {
                    pm.setAttribute('data-orario', orario);
                    console.log('💾 Orario salvato sul modal:', orario);
                }
                
                pm.style.display = 'flex';
                
                // Suono notifica POTENTE 🔊 (TEMPORANEAMENTE DISATTIVATO)
                /* try {
                    const audioContext = new (window.AudioContext || window.webkitAudioContext)();
                    
                    // Primo beep ALTO
                    const osc1 = audioContext.createOscillator();
                    const gain1 = audioContext.createGain();
                    osc1.connect(gain1);
                    gain1.connect(audioContext.destination);
                    osc1.frequency.value = 1200; // Frequenza alta
                    gain1.gain.value = 0.4; // Volume alto
                    osc1.start(audioContext.currentTime);
                    osc1.stop(audioContext.currentTime + 0.15);
                    
                    // Secondo beep BASSO (dopo pausa)
                    const osc2 = audioContext.createOscillator();
                    const gain2 = audioContext.createGain();
                    osc2.connect(gain2);
                    gain2.connect(audioContext.destination);
                    osc2.frequency.value = 900; // Frequenza media
                    gain2.gain.value = 0.4; // Volume alto
                    osc2.start(audioContext.currentTime + 0.2);
                    osc2.stop(audioContext.currentTime + 0.4);
                    
                    console.log('🔊 Suono POTENTE riprodotto!');
                } catch (e) {
                    console.log('⚠️ Suono non riproducibile:', e);
                } */
                
                console.log('✅ Modal mostrato!');
            } catch (error) {
                console.error('❌ ERRORE mostraPromozioneModal:', error);
            }
        }
        
        function closePromoModal(){
            const pm=document.getElementById('promo-modal');
            if(pm){
                // Recupera la vistaKey dall'attributo data O dalla variabile globale
                let vistaKey = pm.getAttribute('data-vista-key') || currentPromoVistaKey;
                console.log('🔍 closePromoModal - VistaKey recuperata:', vistaKey, '(da attributo:', pm.getAttribute('data-vista-key'), ', da globale:', currentPromoVistaKey + ')');
                
                if(vistaKey){
                    // Usa localStorage direttamente (senza prefisso AGENDA_ID) per evitare problemi se AGENDA_ID cambia
                    localStorage.setItem(vistaKey, 'true');
                    console.log('✅ Promozione marcata come vista e salvata:', vistaKey);
                    // Verifica che sia stata salvata
                    const verificato = localStorage.getItem(vistaKey);
                    console.log('🔍 Verifica salvataggio - Leggendo:', verificato, '(Chiave completa:', vistaKey + ')');
                    // Resetta la variabile globale
                    currentPromoVistaKey = null;
                } else {
                    console.warn('⚠️ VistaKey non trovata né sul modal né in variabile globale!');
                }
                pm.style.display='none';
            } else {
                console.warn('⚠️ Modal promozione non trovato!');
            }
        }
        
        // RESET COMPLETO APP (per test e debug)
        function resetCompleteApp() {
            if (confirm('⚠️ ATTENZIONE!\n\nQuesto cancellerà:\n- Codice attivazione\n- Tutti gli appuntamenti\n- Tutti i documenti\n- Tutte le promozioni viste\n\nSei sicuro?')) {
                console.log('🔄 Reset completo in corso...');
                storage.clear();
                if (db) {
                    indexedDB.deleteDatabase('VetDocumentsDB');
                }
                alert('✅ Reset completato!\n\nLa pagina verrà ricaricata.');
                location.reload();
            }
        }

// ========== SISTEMA PROMOZIONI ==========
        let coverPrefilled = false;
        function setCoverOverlayFromPromo(promo) {
            if (coverPrefilled) return;
            if (!promo) return;
            const titleEl = document.getElementById('cover-title');
            const msgEl = document.getElementById('cover-message');
            const imgEl = document.getElementById('cover-image');
            if (titleEl && promo.titolo) titleEl.value = promo.titolo;
            if (msgEl && promo.messaggio) msgEl.value = promo.messaggio;
            if (imgEl) {
                if (promo.img_url) {
                    imgEl.src = encodeURI(promo.img_url);
                    imgEl.style.display = 'block';
                } else {
                    imgEl.style.display = 'none';
                }
            }
            coverPrefilled = true;
        }

        (function initCoverOverlay() {
            const overlay = document.getElementById('cover-overlay');
            const closeBtn = document.getElementById('cover-close-btn');
            if (!overlay) return;
            overlay.style.display = 'none';
            if (closeBtn) {
                closeBtn.addEventListener('click', () => {
                    overlay.style.display = 'none';
                });
            }
        })();

        // Variabile globale per salvare la vistaKey della promozione corrente
        let currentPromoVistaKey = null;

        function shouldBlockAutoPopup(){
            try {
                if (window.location.href.indexOf('/PRENOTAZIONI/view-promozione.html') !== -1) return true;
                if (sessionStorage.getItem('promoPushViewActive') === 'true') return true;
            } catch (e) {
                console.warn('Blocco popup automatici non disponibile:', e);
            }
            return false;
        }
        
        function checkPromozioniToilettatura(){
            console.log('🚀 Controllo promozioni...');
            fetch('../promo_api-toilet-001.php')
                .then(r=>{
                    console.log('📨 Risposta API promozioni ricevuta:', r.status);
                    return r.json();
                })
                .then(data=>{
                    console.log('📦 Promozioni ricevute:', data);
                    const promozioni = data.promozioni || data;
                    if(Array.isArray(promozioni)&&promozioni.length>0){
                        console.log('✅ Trovate', promozioni.length, 'promozioni');
                        try {
                            setCoverOverlayFromPromo(promozioni[0]);
                        } catch (e) {
                            console.warn('Impostazione copertina da promo fallita:', e);
                        }
                        
                        const blockAutoPopup = shouldBlockAutoPopup();
                        promozioni.forEach(promo=>{
                            if (blockAutoPopup) {
                                console.log('⛔ Popup automatici bloccati durante visualizzazione PUSH.');
                                return;
                            }
                            // Controlla se la promozione è valida in base alle date
                            const oggi = new Date();
                            oggi.setHours(0, 0, 0, 0);
                            
                            const dataInizio = new Date(promo.data_inizio);
                            dataInizio.setHours(0, 0, 0, 0);
                            
                            const dataFine = new Date(promo.data_fine);
                            dataFine.setHours(23, 59, 59, 999);
                            
                            // Verifica se la promozione è nel periodo valido
                            if (oggi < dataInizio) {
                                console.log('⌛ Promo ID', promo.id, '- Non ancora iniziata (inizia:', promo.data_inizio + ')');
                                return;
                            }
                            
                            if (oggi > dataFine) {
                                console.log('⏳ Promo ID', promo.id, '- Scaduta (finiva:', promo.data_fine + ')');
                                return;
                            }
                            
                            // ========== PARAMETRI CONFIGURABILI ==========
                            // Usa valori dal JSON, oppure default se non specificati
                            const maxVisualizzazioni = promo.max_visualizzazioni || 3; // Default: 3 volte
                            
                            // Leggi gli orari configurati
                            let orariConfigurati = [];
                            if (promo.popup_orari) {
                                try {
                                    orariConfigurati = typeof promo.popup_orari === 'string' ? 
                                        JSON.parse(promo.popup_orari) : 
                                        promo.popup_orari;
                                    if (!Array.isArray(orariConfigurati)) {
                                        orariConfigurati = [];
                                    }
                                } catch (e) {
                                    console.warn('⚠️ Errore parsing orari:', e);
                                    orariConfigurati = [];
                                }
                            }
                            
                            // Se ci sono orari configurati, controlla se è il momento di mostrarli
                            if (promo.popup_attivo == 1 && orariConfigurati.length > 0) {
                                const oraAttuale = new Date();
                                const oraAttualeNum = oraAttuale.getHours();
                                const minutoAttualeNum = oraAttuale.getMinutes();
                                const oraAttualeStr = String(oraAttualeNum).padStart(2, '0') + ':' + 
                                                    String(minutoAttualeNum).padStart(2, '0');
                                
                                const dataOggi = oggi.toISOString().split('T')[0];
                                
                                // Trova l'ULTIMO orario passato che non è stato ancora mostrato
                                let ultimoOrarioDaMostrare = null;
                                
                                for (const orario of orariConfigurati) {
                                    const [ora, minuto] = orario.split(':');
                                    const oraTarget = parseInt(ora);
                                    const minutoTarget = parseInt(minuto);
                                    
                                    // Controlla se questo orario è già stato mostrato oggi
                                    const chiaveOrario = 'PROMO_' + promo.id + '_orario_' + orario + '_' + dataOggi;
                                    const giaMostrato = localStorage.getItem(chiaveOrario);
                                    
                                    if (giaMostrato) {
                                        continue; // Già mostrato, vai al prossimo
                                    }
                                    
                                    // Controlla se siamo oltre questo orario (l'orario è passato o è adesso)
                                    const oraPassed = (oraAttualeNum > oraTarget) || 
                                                    (oraAttualeNum === oraTarget && minutoAttualeNum >= minutoTarget);
                                    
                                    if (oraPassed) {
                                        // Questo orario è passato e non è stato ancora mostrato - ricordalo
                                        ultimoOrarioDaMostrare = orario;
                                        // Continua il loop per trovare l'ULTIMO orario passato
                                    }
                                }
                                
                                // Se abbiamo trovato un orario passato da mostrare, mostra solo quello
                                if (ultimoOrarioDaMostrare) {
                                    console.log('✅ Promo ID', promo.id, '- Mostro ULTIMO orario passato:', ultimoOrarioDaMostrare);
                                    const chiaveOrario = 'PROMO_' + promo.id + '_orario_' + ultimoOrarioDaMostrare + '_' + dataOggi;
                                    localStorage.setItem(chiaveOrario, 'true');
                                    mostraPromozioneModal(promo, ultimoOrarioDaMostrare);
                                    return;
                                }
                                
                                // Se arriviamo qui, tutti gli orari sono ancora nel futuro o già mostrati
                                console.log('⏭ Promo ID', promo.id, '- Nessun orario da mostrare adesso. Prossimi orari:', orariConfigurati);
                                return;
                            }
                            
                            console.log('📊 Promo ID', promo.id, '- Max visualizzazioni:', maxVisualizzazioni);
                            
                            // USA localStorage DIRETTO con prefisso PROMO_ (separato da AGENDA_ID)
                            const contatoreKey = 'PROMO_' + promo.id + '_contatore';
                            
                            // Leggi contatore
                            let contatore = parseInt(localStorage.getItem(contatoreKey) || '0');
                            
                            console.log('📈 Promo ID', promo.id, '- Contatore:', contatore + '/' + maxVisualizzazioni);
                            
                            // Se ha già raggiunto il massimo, salta
                            if (contatore >= maxVisualizzazioni) {
                                console.log('⛔ Promo ID', promo.id, '- Già mostrata', maxVisualizzazioni, 'volte, STOP');
                                return;
                            }
                            
                            // OK, mostra la promo!
                            console.log('🎯 Mostro promozione:', promo.titolo, '(visualizzazione', (contatore + 1), 'di', maxVisualizzazioni + ')');
                            
                            // Incrementa contatore SUBITO usando localStorage diretto
                            localStorage.setItem(contatoreKey, (contatore + 1).toString());
                            
                            console.log('💾 Salvato - Contatore:', (contatore + 1));
                            
                            mostraPromozioneModal(promo);
                        });
                    } else {
                        console.log('ℹ️ Nessuna promozione disponibile o formato non valido');
                    }
                })
                .catch(err=>{
                    console.error('❌ ERRORE caricamento promozioni:', err);
                });
        }
        // ========== FINE SISTEMA PROMOZIONI ==========

console.log('🚀 SISTEMA PROMOZIONI AVVIATO - Primo controllo tra 1 secondo...');
        // setTimeout(()=>checkPromozioniToilettatura(),1000);
        // setInterval(checkPromozioniToilettatura, 10000); // Controlla ogni 10 secondi
        // document.addEventListener('visibilitychange', () => { if (!document.hidden) checkPromozioniToilettatura(); });
        // ========== FINE PROMOZIONI ==========

// Identifica che la versione remota è attiva (impedisce doppio avvio del codice locale)
      window.__REMOTE_APP__ = true;

      // Messaggio di test per capire che il file remoto si carica
      console.log('Remote app loaded: 2025.11.06-FIX');

      // Qui in futuro metteremo tutta la logica "master" centralizzata.
      // Per ora lasciamo solo il log, così verifichiamo che tutto funziona.

// AUTO-UPDATE: Controlla aggiornamenti ogni 30 secondi
      
      // ========== RICHIESTA PERMESSO NOTIFICHE ========== - DISABILITATA (gestione push esterna)
      /*
      function richiediPermessoNotifiche() {
        // Controlla se già richiesto
        if (localStorage.getItem('notificationPermissionAsked') === 'true') {
          console.log('✅ Permesso notifiche già richiesto');
          return;
        }
        
        // Controlla se il browser supporta le notifiche
        if (!('Notification' in window)) {
          console.warn('❌ Browser non supporta notifiche');
          return;
        }
        
        // Se già concesso, non chiedere di nuovo
        if (Notification.permission === 'granted') {
          console.log('✅ Notifiche già abilitate');
          return;
        }
        
        // Mostra popup personalizzato
        if (confirm('🔔 Abilita notifiche informative utili per te\n\nRiceverai promemoria per i tuoi appuntamenti e informazioni importanti da DOG STYLE!')) {
          Notification.requestPermission().then(function(permission) {
            localStorage.setItem('notificationPermissionAsked', 'true');
            
            if (permission === 'granted') {
              console.log('✅ Permesso notifiche concesso!');
              
              // Invia notifica di test
              if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
                navigator.serviceWorker.ready.then(function(registration) {
                  registration.showNotification('🐕 DOG STYLE', {
                    body: 'Notifiche attivate! Ti avviseremo prima degli appuntamenti.',
                    icon: './icon-192.png',
                    badge: './icon-192.png',
                    vibrate: [200, 100, 200]
                  });
                });
              }
            } else {
              console.log('❌ Permesso notifiche negato');
            }
          });
        } else {
          localStorage.setItem('notificationPermissionAsked', 'true');
          console.log('❌ Utente ha rifiutato le notifiche');
        }
      }
      // Timestamp installazione (Chrome/Android) - DISABILITATO (PWA non attiva)
      window.addEventListener('appinstalled', function() {
        try { 
          localStorage.setItem('installedAt', new Date().toISOString());
         console.log('✅ App installata, timestamp salvato');
        } catch(e) {
          console.error('Errore salvataggio timestamp:', e);
        }
      });
      */

        (function initButtons() {
            function setup() {
                var btn = document.getElementById('btnRegistrati');
        if (btn) {
          btn.disabled = false;
          btn.removeAttribute('disabled');
          btn.style.opacity = '1';
          btn.style.pointerEvents = 'auto';
        }
        // Se l'utente è già attivato e siamo su piattaforma nativa, registra/aggiorna subito il token FCM
        try {
          const code = localStorage.getItem('activationCode') || '';
          const phone = localStorage.getItem('activationPhone') || '';
          if (window.Capacitor?.isNativePlatform?.() && code && phone && typeof registerNativePush === 'function') {
            console.log('[PUSH DEBUG] initButtons->setup: native + activation present, calling registerNativePush');
            registerNativePush().catch(err => console.warn('Native push on init skipped:', err));
          } else {
            console.log('[PUSH DEBUG] initButtons->setup: NOT calling registerNativePush (native=', window.Capacitor?.isNativePlatform ? window.Capacitor.isNativePlatform() : 'n/a', ', code=', code, ', phone=', phone, ')');
          }
        } catch (e) {
          console.warn('Native push init check failed:', e);
        }
        var btnPromo = document.getElementById('btnPromo');
        if (btnPromo) {
          btnPromo.addEventListener('click', function() {
            try {
              sessionStorage.setItem('promoPushViewActive', 'true');
            } catch (e) {
              console.warn('Impossibile salvare stato PROMO:', e);
            }
            window.location.href = '/ristorantemimmo1/PRENOTAZIONI/view-promozione.html';
          });
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setup);

        document.addEventListener('DOMContentLoaded', () => {
            // Prefill di tutti gli input telefono con activationPhone se presente
            try {
                const phoneSaved = localStorage.getItem('activationPhone') || '';
                if (phoneSaved) {
                    document.querySelectorAll('input[type=\"tel\"], input[name*=\"tel\" i], input[name*=\"phone\" i], input[name*=\"telefono\" i]').forEach(el => {
                        if (!el.value) {
                            el.value = phoneSaved;
                        }
                    });
                }
            } catch (e) {
                console.warn('[PUSH DEBUG] Prefill telefono fallito:', e);
            }
        });

    } else {
        setup();
            }
        })();

// SW update periodico (forza aggiornamenti automatici)



