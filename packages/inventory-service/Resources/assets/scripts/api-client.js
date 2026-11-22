/**
 * api-client.js — Passerelle unique pour les requêtes vers les API.
 *
 * Dépendances (déjà présentes globalement) :
 *   - window.getCsrfToken()   : retourne le jeton CSRF courant (sync ou Promise)
 *   - window.sessionExpired() : appelée quand une réponse contient sessionExpired:true
 *   - window.refreshCsrfToken() : (optionnelle) renouvelle le jeton après un 419
 *   - window.Toaster          : (optionnelle) notifications ; sans Toaster, le client fonctionne
 *                               exactement comme avant, sans aucune notification.
 *
 * Expose : window.api (client par défaut), window.ApiError, window.api.create(config)
 *
 * Usage :
 *   const users = await api.get('/api/users', { params: { page: 2 } });
 *   const res   = await api.post('/api/groups', { name: 'Équipe' }, {
 *     toast: { loading: 'Création…', success: 'Groupe créé.' },
 *   });
 *
 * Notifications (option `toast` par requête) :
 *   - (absent)           : les erreurs sont affichées automatiquement (toast rouge/orange).
 *   - false              : aucune notification pour cette requête (ex. formulaire qui gère ses erreurs).
 *   - 'Message'          : toast de succès avec ce message.
 *   - {
 *       loading: 'Envoi…',                 // toast de chargement, remplacé par succès/erreur
 *       success: 'OK' | (data) => 'OK',    // message de succès (ou false)
 *       error:   'KO' | (err) => 'KO',     // remplace le message d'erreur automatique (ou false)
 *       ...options Toaster (title, duration, position, action, id…)  // appliquées au toast de succès
 *     }
 *
 * Intégration avec Toaster.prompt (mode persistent) :
 *   ApiError expose `errors` (= data.errors, validation Laravel) : une erreur 422 renvoyée par
 *   onSubmit est donc affichée directement sur les champs.
 *     Toaster.prompt(fields, { persistent: true,
 *       onSubmit: (values) => api.post('/api/x', values, { toast: false }) });
 */
(() => {
  'use strict';

  /* ------------------------------------------------------------------ */
  /* Erreur normalisée                                                   */
  /* ------------------------------------------------------------------ */
  // Codes : HTTP_ERROR, NETWORK_ERROR, TIMEOUT, ABORTED, SESSION_EXPIRED,
  //         CSRF_MISSING, CSRF_MISMATCH, INVALID_JSON, INVALID_URL, FORBIDDEN_ORIGIN
  class ApiError extends Error {
    constructor(message, { code = 'HTTP_ERROR', status = 0, data = null, url = '', method = '', cause } = {}) {
      super(message);
      this.name = 'ApiError';
      this.code = code;
      this.status = status;
      this.data = data;       // corps de la réponse (ex. data.errors pour la validation)
      this.url = url;
      this.method = method;
      this.notified = false;  // true si un toast a déjà été affiché pour cette erreur
      if (cause) this.cause = cause;
    }
    get isNetwork() { return this.code === 'NETWORK_ERROR' || this.code === 'TIMEOUT'; }
    // Erreurs de validation { champ: ['message'] } : compatible avec Toaster.prompt (setErrors).
    get errors() {
      const e = this.data && this.data.errors;
      return e && typeof e === 'object' ? e : null;
    }
  }

  /* ------------------------------------------------------------------ */
  /* Configuration par défaut                                            */
  /* ------------------------------------------------------------------ */
  const DEFAULTS = Object.freeze({
    baseUrl: '',                       // ex. '/api' ; chemins absolus acceptés si même origine
    timeout: 30000,                    // ms, par tentative
    retries: 2,                        // tentatives supplémentaires (méthodes idempotentes seulement)
    retryDelay: 300,                   // ms, backoff exponentiel + jitter
    retryMethods: ['GET', 'HEAD', 'OPTIONS'],
    retryStatuses: [408, 429, 502, 503, 504],
    // Statuts qui bloquent l'endpoint : jamais rejoués, et plus aucune requête n'est envoyée.
    blockStatuses: { 424: 'DEPENDENCY_FAILED', 503: 'SERVICE_UNAVAILABLE' },
    blockTtl: 0,                       // ms ; 0 = bloqué jusqu'au rechargement (ou unblock())
    csrfStatuses: [419],               // statuts signalant un jeton CSRF invalide/expiré
    refreshCsrfToken: null,            // async () => void ; sinon window.refreshCsrfToken si elle existe
    allowedOrigins: [],                // origines externes autorisées (par défaut : même origine uniquement)
    sessionExpiredDedupMs: 2000,       // évite N appels de sessionExpired() pour N requêtes parallèles

    // --- Notifications Toaster ---
    toasts: true,                      // false : désactive les toasts d'erreur automatiques (client entier)
    errorToastOptions: { duration: 7000 }, // options Toaster ajoutées aux toasts d'erreur
    messages: {                        // textes affichés pour les erreurs sans message serveur
      network: 'Connexion impossible. Vérifiez votre réseau.',
      timeout: 'Le serveur met trop de temps à répondre.',
      csrf: 'Votre session de sécurité a expiré. Rechargez la page.',
      unauthorized: 'Authentification requise.',
      forbidden: 'Vous n\'avez pas les droits pour effectuer cette action.',
      notFound: 'Ressource introuvable.',
      tooMany: 'Trop de requêtes. Réessayez dans un instant.',
      server: 'Le serveur a rencontré une erreur. Réessayez plus tard.',
      generic: 'Une erreur est survenue.',
      dependency: 'Un service requis est indisponible.',
      blocked: 'Ce service est indisponible pour le moment.',
    },
  });

  const UNSAFE_METHODS = new Set(['POST', 'PUT', 'PATCH', 'DELETE']);
  const MAX_RETRY_AFTER_MS = 10000;
  // Erreurs qui ne doivent jamais produire de toast automatique.
  const SILENT_CODES = new Set(['ABORTED', 'SESSION_EXPIRED', 'ENDPOINT_BLOCKED']);

  /* ------------------------------------------------------------------ */
  /* Utilitaires                                                         */
  /* ------------------------------------------------------------------ */
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  const isPlainObject = (v) => {
    if (v === null || typeof v !== 'object') return false;
    const p = Object.getPrototypeOf(v);
    return p === Object.prototype || p === null;
  };

  const getToaster = () => {
    const t = window.Toaster;
    return t && typeof t === 'object' ? t : null;
  };

  // Appel Toaster protégé : une panne d'affichage ne doit jamais casser une requête.
  function safeToast(fn) {
    try { return fn(getToaster()); } catch (e) { console.error('[api] Toaster a échoué :', e); return null; }
  }

  function parseRetryAfter(value) {
    if (!value) return null;
    const secs = Number(value);
    if (Number.isFinite(secs)) return Math.min(Math.max(secs * 1000, 0), MAX_RETRY_AFTER_MS);
    const date = Date.parse(value);
    if (Number.isFinite(date)) return Math.min(Math.max(date - Date.now(), 0), MAX_RETRY_AFTER_MS);
    return null;
  }

  function backoff(attempt, base) {
    const exp = base * 2 ** attempt;
    return exp / 2 + Math.random() * (exp / 2); // jitter
  }
  function getCsrfToken() { const metaTag = document.querySelector('meta[name="csrf_token"]'); return metaTag ? metaTag.getAttribute('content') : ''; }

  /* ------------------------------------------------------------------ */
  /* Fabrique du client                                                  */
  /* ------------------------------------------------------------------ */
  function createClient(userConfig = {}) {
    const config = {
      ...DEFAULTS,
      ...userConfig,
      messages: { ...DEFAULTS.messages, ...(userConfig.messages || {}) },
      blockStatuses: { ...DEFAULTS.blockStatuses, ...(userConfig.blockStatuses || {}) },
    };
    let lastSessionExpiredAt = 0;
    let csrfRefreshPromise = null; // partagé : N requêtes en 419 → un seul rafraîchissement
    const blocked = new Map(); // clé endpoint → { status, code, data, until }
    /* ---- URL ------------------------------------------------------- */
    function buildUrl(path, params, method) {
      if (typeof path !== 'string' || path === '') {
        throw new ApiError('URL de requête invalide.', { code: 'INVALID_URL', method });
      }

      let url;
      try {
        const isAbsolute = /^([a-z][a-z0-9+.-]*:)?\/\//i.test(path);
        if (isAbsolute) {
          url = new URL(path, window.location.origin);
        } else {
          const base = new URL(config.baseUrl || '/', window.location.origin);
          if (!base.pathname.endsWith('/')) base.pathname += '/';
          url = new URL(path.replace(/^\/+/, ''), base);
        }
      } catch (e) {
        throw new ApiError('URL de requête invalide.', { code: 'INVALID_URL', method, cause: e });
      }

      // Le jeton CSRF ne doit jamais partir vers une origine non approuvée.
      const allowed = [window.location.origin, ...config.allowedOrigins];
      if (!allowed.includes(url.origin) || !/^https?:$/.test(url.protocol)) {
        throw new ApiError('Origine non autorisée pour cette requête.', {
          code: 'FORBIDDEN_ORIGIN', url: url.origin, method,
        });
      }

      if (params && typeof params === 'object') {
        const appendParam = (key, value) => {
          if (value === undefined || value === null) return;
          if (value instanceof Date) {
            url.searchParams.append(key, value.toISOString());
            return;
          }
          if (Array.isArray(value)) {
            value.forEach((item) => appendParam(`${key}[]`, item));
            return;
          }
          if (isPlainObject(value)) {
            Object.entries(value).forEach(([nestedKey, nestedValue]) => {
              appendParam(`${key}[${nestedKey}]`, nestedValue);
            });
            return;
          }
          url.searchParams.append(key, String(value));
        };

        for (const [key, value] of Object.entries(params)) {
          appendParam(key, value);
        }
      }
      return url;
    }

    /* ---- En-têtes -------------------------------------------------- */
    async function buildHeaders(extra, method, hasJsonBody) {
      const headers = new Headers();

      // En-têtes de l'appelant d'abord…
      if (extra) new Headers(extra).forEach((v, k) => headers.set(k, v));
      if (hasJsonBody) headers.set('Content-Type', 'application/json');

      // …puis les en-têtes obligatoires, non écrasables.
      let token = '';
      try {
        token = await getCsrfToken();
      } catch (_) { /* géré ci-dessous */ }

      if (!token && UNSAFE_METHODS.has(method)) {
        throw new ApiError('Jeton CSRF indisponible : requête annulée.', { code: 'CSRF_MISSING', method });
      }
      headers.set('x-csrf-token', token || '');
      headers.set('Accept', 'application/json');
      headers.set('X-Requested-With', 'XMLHttpRequest');
      return headers;
    }

    /* ---- Corps de la réponse -------------------------------------- */
    async function readBody(response, responseType, ctx) {
      if (response.status === 204 || response.status === 205) return null;
      if (responseType === 'blob') return response.blob();

      const text = await response.text();
      if (responseType === 'text') return text;
      if (text === '') return null;

      const contentType = response.headers.get('content-type') || '';
      if (!/json/i.test(contentType)) {
        // Réponse non-JSON (ex. page HTML d'un proxy) : on la garde en texte brut.
        return { __raw: text };
      }
      try {
        return JSON.parse(text);
      } catch (e) {
        throw new ApiError('Réponse JSON invalide.', { code: 'INVALID_JSON', status: response.status, ...ctx, cause: e });
      }
    }

    /* ---- Session expirée ------------------------------------------ */
    function handleSessionExpired() {
      const now = Date.now();
      if (now - lastSessionExpiredAt < config.sessionExpiredDedupMs) return;
      lastSessionExpiredAt = now;
      try {
        if (typeof window.sessionExpired === 'function') window.sessionExpired();
      } catch (e) {
        console.error('[api] window.sessionExpired() a échoué :', e);
      }
    }

    /* ---- Rafraîchissement du jeton CSRF ---------------------------- */
    // Retourne true si une fonction de rafraîchissement a été exécutée avec succès.
    function refreshCsrf() {
      if (!csrfRefreshPromise) {
        const fn = config.refreshCsrfToken
          || (typeof window.refreshCsrfToken === 'function' ? window.refreshCsrfToken : null);
        csrfRefreshPromise = (async () => {
          if (!fn) return false;
          try { await fn(); return true; } catch (_) { return false; }
        })().finally(() => { csrfRefreshPromise = null; });
      }
      return csrfRefreshPromise;
    }

    /* ---- Notifications --------------------------------------------- */
    // Normalise l'option `toast` d'une requête.
    function normalizeToast(opt) {
      if (opt === false) return { off: true };
      if (typeof opt === 'string') return { success: opt };
      if (opt && typeof opt === 'object') {
        const { loading, success, error, ...rest } = opt;
        return { loading, success, error, rest };
      }
      return {};
    }

    const resolveMsg = (m, arg) => (typeof m === 'function' ? m(arg) : m);

    // Message d'erreur lisible pour l'utilisateur.
    function describeError(err) {
      const m = config.messages;
      switch (err.code) {
        case 'NETWORK_ERROR': return m.network;
        case 'TIMEOUT': return m.timeout;
        case 'CSRF_MISSING':
        case 'CSRF_MISMATCH': return m.csrf;
        case 'INVALID_JSON':
        case 'INVALID_URL':
        case 'FORBIDDEN_ORIGIN': return m.generic;
        case 'DEPENDENCY_FAILED':
        case 'SERVICE_UNAVAILABLE': return m.dependency;
        case 'ENDPOINT_BLOCKED': return m.blocked;
        default: break;
      }
      const d = err.data;
      const serverMsg = d && typeof d === 'object' && (d.message || d.error);
      if (err.status >= 500) return m.server; // jamais de message technique brut pour une 5xx
      if (typeof serverMsg === 'string' || typeof serverMsg === 'number') {
        if (String(serverMsg).trim()) return String(serverMsg);
      }
      if (serverMsg && typeof serverMsg === 'object') {
        const formatted = getToaster()?.format?.(serverMsg);
        if (formatted) return formatted;
      }
      if (err.status === 401) return m.unauthorized;
      if (err.status === 403) return m.forbidden;
      if (err.status === 404) return m.notFound;
      if (err.status === 429) return m.tooMany;
      return err.message || m.generic;
    }

    // Affiche l'erreur (sauf codes silencieux). Retourne true si un toast a été affiché.
    function notifyError(err, tc) {
      if (config.toasts === false || tc.off || tc.error === false) return false;
      if (!(err instanceof ApiError) || SILENT_CODES.has(err.code)) return false;
      if (!getToaster()) return false;

      const base = { ...config.errorToastOptions };

      // Message d'erreur personnalisé pour la requête
      if (tc.error !== undefined) {
        const msg = resolveMsg(tc.error, err);
        if (!msg) return false;
        safeToast((T) => T.danger(String(msg), base));
        return true;
      }

      // Les API peuvent renvoyer les erreurs sous forme de chaîne, tableau ou objet.
      const rawErrors = err.data && typeof err.data === 'object' ? err.data.errors : null;
      if (err.status < 500 && rawErrors !== undefined && rawErrors !== null && rawErrors !== '') {
        const shown = safeToast((T) => T.show(rawErrors, { ...base, type: 'warning' }));
        if (shown && shown.length) return true;
      }

      // Id d'anti-doublon : N requêtes parallèles en échec → un seul toast.
      safeToast((T) => T.danger(describeError(err), { ...base, id: `api-error:${err.code}:${err.status}` }));
      return true;
    }

    /* ---- Une tentative -------------------------------------------- */
    async function attempt(url, method, headers, body, opts) {
      const controller = new AbortController();
      let timedOut = false;
      const timer = setTimeout(() => { timedOut = true; controller.abort(); }, opts.timeout);

      const onCallerAbort = () => controller.abort();
      if (opts.signal) {
        if (opts.signal.aborted) controller.abort();
        else opts.signal.addEventListener('abort', onCallerAbort, { once: true });
      }

      const ctx = { url: url.pathname, method };
      try {
        const response = await fetch(url, {
          method,
          headers,
          body,
          signal: controller.signal,
          credentials: url.origin === window.location.origin ? 'same-origin' : 'omit',
          mode: url.origin === window.location.origin ? 'same-origin' : 'cors',
          referrerPolicy: 'same-origin',
          redirect: 'follow',
        });
        const data = await readBody(response, opts.responseType, { ...ctx });
        return { response, data };
      } catch (e) {
        if (e instanceof ApiError) throw e;
        if (timedOut) throw new ApiError('Délai d\'attente dépassé.', { code: 'TIMEOUT', ...ctx, cause: e });
        if (opts.signal && opts.signal.aborted) throw new ApiError('Requête annulée.', { code: 'ABORTED', ...ctx, cause: e });
        throw new ApiError('Erreur réseau.', { code: 'NETWORK_ERROR', ...ctx, cause: e });
      } finally {
        clearTimeout(timer);
        if (opts.signal) opts.signal.removeEventListener('abort', onCallerAbort);
      }
    }
    const endpointKey = (method, url) => `${method} ${url.origin}${url.pathname}`;

    // Retourne l'entrée de blocage si la requête doit être refusée, sinon null.
    function checkBlocked(key) {
      const entry = blocked.get(key);
      if (!entry) return null;
      if (entry.until && Date.now() >= entry.until) {
        entry.until = Date.now() + config.blockTtl; // test en cours : les autres restent bloquées
        return null;                                 // succès → delete ; statut bloquant → markBlocked
      }
      return entry;
    }

    function markBlocked(key, status, data) {
      blocked.set(key, {
        status,
        code: config.blockStatuses[status],
        data,
        until: config.blockTtl > 0 ? Date.now() + config.blockTtl : 0,
      });
    }
    /* ---- Requête complète (avec retries), sans notification ------- */
    async function execute(methodRaw, path, options = {}) {
      const method = String(methodRaw).toUpperCase();
      const opts = {
        timeout: options.timeout ?? config.timeout,
        retries: options.retries ?? (config.retryMethods.includes(method) ? config.retries : 0),
        responseType: options.responseType || 'json', // 'json' | 'text' | 'blob'
        signal: options.signal,
      };

      const url = buildUrl(path, options.params, method);
      const key = endpointKey(method, url);

      const block = checkBlocked(key);
      if (block) {
        throw new ApiError(config.messages.blocked, {
          code: 'ENDPOINT_BLOCKED', status: block.status, data: block.data,
          url: url.pathname, method,
        });
      }
      // Corps
      let body;
      let jsonBody = false;
      if (options.body !== undefined && options.body !== null && method !== 'GET' && method !== 'HEAD') {
        if (isPlainObject(options.body) || Array.isArray(options.body)) {
          body = JSON.stringify(options.body);
          jsonBody = true;
        } else {
          body = options.body; // FormData, Blob, URLSearchParams, string…
        }
      }

      let csrfRetried = false; // un seul nouvel essai après un 419
      for (let n = 0; ; n++) {
        // Jeton relu à chaque tentative : il peut avoir été renouvelé.
        const headers = await buildHeaders(options.headers, method, jsonBody);
        const sentToken = headers.get('x-csrf-token');
        let result;
        try {
          result = await attempt(url, method, headers, body, opts);
        } catch (err) {
          const retryable = err.code === 'NETWORK_ERROR' || err.code === 'TIMEOUT';
          if (retryable && n < opts.retries) {
            await sleep(backoff(n, config.retryDelay));
            continue;
          }
          throw err;
        }

        const { response, data } = result;
        const ctx = { status: response.status, url: url.pathname, method, data };

        // 1) Session expirée (quel que soit le statut HTTP)
        if (data && typeof data === 'object' && data.sessionExpired === true) {
          handleSessionExpired();
          throw new ApiError('Session expirée.', { code: 'SESSION_EXPIRED', ...ctx });
        }

        // 2) Succès
        if (response.ok) {
          blocked.delete(key);   // l'endpoint répond de nouveau
          return options.fullResponse ? { data, status: response.status, headers: response.headers } : data;
        }

        // 3) Jeton CSRF expiré (419) : rafraîchir puis rejouer UNE fois.
        //    Le serveur a rejeté la requête avant traitement : rejouer est sûr, même un POST.
        if (!csrfRetried && config.csrfStatuses.includes(response.status)) {
          csrfRetried = true;
          const refreshed = await refreshCsrf();
          let current = '';
          try { current = await window.getCsrfToken(); } catch (_) { /* ignoré */ }
          if (current && (refreshed || current !== sentToken)) {
            n--; // ce nouvel essai ne consomme pas le budget de retries
            continue;
          }
        }

        // 4) Statut à réessayer (sauf statuts bloquants, traités juste après)
        if (
          n < opts.retries
          && config.retryStatuses.includes(response.status)
          && config.blockStatuses[response.status] === undefined
        ) {
          const wait = parseRetryAfter(response.headers.get('Retry-After')) ?? backoff(n, config.retryDelay);
          await sleep(wait);
          continue;
        }

        // 4 bis) Statut bloquant : mémoriser, ne plus envoyer.
        if (config.blockStatuses[response.status] !== undefined) {
          markBlocked(key, response.status, data);
          const message = (data && typeof data === 'object' && (data.message || data.error)) || `Erreur ${response.status}`;
          throw new ApiError(String(message), {
            code: config.blockStatuses[response.status], ...ctx,
          });
        }

        // 5) Erreur HTTP
        const message =
          (data && typeof data === 'object' && (data.message || data.error)) ||
          `Erreur ${response.status}`;
        const code = config.csrfStatuses.includes(response.status) ? 'CSRF_MISMATCH' : 'HTTP_ERROR';
        throw new ApiError(String(message), { code, ...ctx });
      }
    }

    /* ---- Requête avec notifications Toaster ----------------------- */
    async function request(methodRaw, path, options = {}) {
      const tc = normalizeToast(options.toast);
      const toastsOn = config.toasts !== false && !tc.off && !!getToaster();

      // Toast de chargement (persistant, remplacé à la fin de la requête)
      let loadingToast = null;
      if (toastsOn && tc.loading) {
        loadingToast = safeToast((T) => T.loading(String(tc.loading)));
      }
      const closeLoading = () => {
        if (loadingToast) { safeToast(() => loadingToast.dismiss()); loadingToast = null; }
      };

      try {
        const result = await execute(methodRaw, path, options);

        if (toastsOn && tc.success) {
          const payload = options.fullResponse && result ? result.data : result;
          const msg = resolveMsg(tc.success, payload);
          if (msg) {
            if (loadingToast) {
              // On transforme le toast de chargement en toast de succès (pas de clignotement).
              safeToast(() => loadingToast.update(String(msg), { type: 'success', ...tc.rest }));
              loadingToast = null;
            } else {
              safeToast((T) => T.success(String(msg), tc.rest));
            }
          }
        }
        return result;
      } catch (err) {
        if (err instanceof ApiError) {
          const msgFromSuccessToast = loadingToast;
          if (msgFromSuccessToast && !SILENT_CODES.has(err.code)
            && tc.error !== false && config.toasts !== false) {
            // Le toast de chargement devient directement le toast d'erreur.
            const msg = tc.error !== undefined ? resolveMsg(tc.error, err) : describeError(err);
            if (msg) {
              safeToast(() => msgFromSuccessToast.update(String(msg), { type: 'danger', ...config.errorToastOptions }));
              loadingToast = null;
              err.notified = true;
              throw err;
            }
          }
          closeLoading();
          err.notified = notifyError(err, tc);
        } else {
          closeLoading();
        }
        throw err;
      } finally {
        closeLoading(); // garde-fou : ne jamais laisser un toast de chargement orphelin
      }
    }

    /* ---- API publique --------------------------------------------- */
    const client = {
      request,
      get: (path, opts) => request('GET', path, opts),
      delete: (path, opts) => request('DELETE', path, opts),
      post: (path, body, opts) => request('POST', path, { ...opts, body }),
      put: (path, body, opts) => request('PUT', path, { ...opts, body }),
      patch: (path, body, opts) => request('PATCH', path, { ...opts, body }),
      create: (overrides) => createClient({ ...config, ...overrides }),
      isBlocked: (method, path, params) => !!blocked.get(endpointKey(String(method).toUpperCase(), buildUrl(path, params, method))),
      unblock: (method, path) => { blocked.delete(endpointKey(String(method).toUpperCase(), buildUrl(path, undefined, method))); },
      unblockAll: () => blocked.clear(),
    };
    return Object.freeze(client);
  }

  /* ------------------------------------------------------------------ */
  /* Exposition globale (non écrasable)                                  */
  /* ------------------------------------------------------------------ */
  Object.defineProperty(window, 'ApiError', { value: ApiError, writable: false, configurable: false });
  Object.defineProperty(window, 'api', { value: createClient(), writable: false, configurable: false });
})();