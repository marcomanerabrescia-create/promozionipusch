/* ========= SERVICE WORKER VAPID PURO ========= */
const CACHE_VERSION = 'ristorante-v-1770997099';

self.addEventListener('install', (event) => {
  // Attiva subito il nuovo SW
  console.log('[SW] install start', { version: CACHE_VERSION }); // HARDENING
  event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    try {
      const keys = await caches.keys();
      await Promise.all(
        keys
          .filter((key) => key !== CACHE_VERSION && key !== 'ultimaPromo')
          .map((key) => caches.delete(key))
      );
    } catch (err) {
      console.warn('[SW] Cache cleanup error:', err);
    }
    try {
      await self.clients.claim();
      const allClients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
      allClients.forEach((client) => {
        client.postMessage({ type: 'SW_VERSION', version: CACHE_VERSION });
      });
      console.log('[SW] activate completed', { version: CACHE_VERSION, clients: allClients.length }); // HARDENING
    } catch (err) {
      console.warn('[SW] Activate notify error:', err);
    }
  })());
});

self.addEventListener('push', event => {
  event.waitUntil((async () => {
    console.log('[PUSH DEBUG] push event raw:', event);
    let rawData = {};
    let bodyText = '';
    let title = 'Nuovo messaggio';
    let extraData = {};
    let isPromo = false;
    try {
      rawData = event.data ? event.data.json() : {};
      console.log('[PUSH DEBUG] parsed payload:', rawData);
    } catch (e) {
      console.warn('[PUSH DEBUG] payload json parse error:', e);
      rawData = {};
    }

    // Estrae titolo/body da rawData o da rawData.payload (come inviato dal VPS)
    const payload = rawData.payload || rawData;
    title = payload.title || rawData.title || title;
    extraData = payload.data || rawData.data || {};
    bodyText = payload.body
      || payload.message
      || rawData.body
      || rawData.message
      || extraData.body
      || extraData.message
      || bodyText;
    if (!payload || (!title && !bodyText && (!extraData || (!extraData.title && !extraData.body && !extraData.message)))) { // HARDENING
      console.warn('[PUSH DEBUG] payload mancante o incompleto'); // HARDENING
    }
// PROMEMORIA BLOCCANTE (NON CONFONDERE FLUSSI):
// - PROMO: payload/source/tipo/isPromo = promo/true → USA SOLO cache 'ultimaPromo' per l’ULTIMA promo ricevuta.
// - MESSAGGI 1-to-1: tutto il resto → NON devono mai finire in 'ultimaPromo'.
// - È vietato salvare 1-to-1 in 'ultimaPromo'. La cache 'ultimaPromo' è riservata esclusivamente alle promo.
// - notificationclick gestisce la promo con fromPush; non toccare la logica.
// promo se indicato da source/tipo/isPromo nel payload o data
    const sourceVal = ((payload.source || extraData.source || '') + '').toLowerCase();
    const tipoVal = ((payload.tipo || extraData.tipo || '') + '').toLowerCase();
    const typeVal = ((extraData.type || '') + '').toLowerCase();
    const flagPromo = payload.isPromo === true || extraData.isPromo === true;
    isPromo = flagPromo || sourceVal === 'promo' || tipoVal === 'promo';
    const isConferma = typeVal === 'conferma' || sourceVal === 'conferma' || tipoVal === 'conferma';

    // Fallback: se il body è vuoto, prova a leggere come testo grezzo
    if (!bodyText && event.data) {
      try {
        bodyText = event.data.text();
        console.log('[PUSH DEBUG] fallback text payload:', bodyText);
      } catch (err) {
        console.warn('[PUSH DEBUG] fallback text parse error:', err);
      }
    }

    const baseView = isPromo ? 'view-promozione.html' : (isConferma ? 'view-conferma.html' : 'view-messaggio.html');
    const viewUrl = `/ristorantemimmo1/PRENOTAZIONI/${baseView}?title=${encodeURIComponent(title)}&body=${encodeURIComponent(bodyText)}`;
    const options = {
      body: bodyText,
      icon: rawData.icon || payload.icon || '/ristorantemimmo1/PRENOTAZIONI/icons/piatto72x72.png',
      badge: rawData.badge || payload.badge || '/ristorantemimmo1/PRENOTAZIONI/badge-72.png',
      data: Object.assign({}, extraData, { url: viewUrl, title, body: bodyText, isPromo })
    };

    if (isPromo) {
      try {
        const cache = await caches.open('ultimaPromo');
        const promoData = JSON.stringify({ title, body: bodyText, isPromo, timestamp: new Date().toISOString() });
        await cache.put('ultimaPromo', new Response(promoData));
        console.log('[PUSH DEBUG] cached ultimaPromo', promoData);
      } catch (err) {
        console.warn('[PUSH DEBUG] cache promo error:', err);
      }
    }

    console.log('[PUSH DEBUG] showNotification ->', { title, options });

    try {
      await self.registration.showNotification(title, options);
      console.log('[PUSH DEBUG] notification shown');
    } catch (err) {
      console.error('[PUSH DEBUG] showNotification error:', err);
    }
  })());
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  event.waitUntil((async () => {
    const data = event.notification.data || {};
    const target = data.url;
    if (!target) { console.warn('[PUSH DEBUG] notificationclick senza url'); return; } // HARDENING

    const isPromo = data.isPromo || data.source === 'promo';
    const finalUrl = isPromo && target.includes('?') ? target + '&fromPush=1' : target;
    
    const allClients = await clients.matchAll({ type: 'window', includeUncontrolled: true });
    
    for (const client of allClients) {
      if (client.url.includes('agenda-cliente-toilet-001.html')) {
        await client.focus();
        client.navigate(finalUrl);
        return;
      }
    }
    
    try { // HARDENING
      await clients.openWindow(finalUrl);
    } catch (err) {
      console.warn('[PUSH DEBUG] openWindow fallita, retry una volta', err); // HARDENING
      try { await clients.openWindow(finalUrl); } catch (err2) { console.warn('[PUSH DEBUG] openWindow retry fallita', err2); } // HARDENING
    }
  })());
});
/* ========= FINE FILE ========= */
