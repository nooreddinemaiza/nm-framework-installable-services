/*!
 * Form 2.1 : formulaires modaux autonomes (Tailwind CSS). Aucune dépendance, aucun lien avec Toaster.
 *
 * PRÉREQUIS
 *  - Tailwind CSS v3.4+ (ou v4) avec le mode sombre actif (darkMode 'class' ou 'media').
 *  - Si vous compilez Tailwind, ajoutez ce fichier à "content" (classes écrites en entier).
 *
 * UTILISATION
 *    const data = await Form.edit({
 *        title: 'Organisation',
 *        values: { name: 'Acme' },
 *        fields: [ { name: 'name', label: 'Titre', type: 'text', required: true } ],
 *        onSubmit: async (data, { close, setErrors, changes }) => { ... close(); },
 *    });
 *    // ou, en fluide :
 *    await Form.instance({ variant: 'new' })
 *        .text('name', { label: 'Titre', required: true })
 *        .slug('slug', { label: 'Slug', from: 'name', prefix: '/org/' })
 *        .row([ { type: 'text', name: 'zip', label: 'Code postal' }, { type: 'text', name: 'city', label: 'Ville' } ])
 *        .open();
 *
 *  Valeur retournée (même logique que prompt) :
 *    null (annulé) | valeur seule (1 seul champ) | { champ: valeur } | true (aucun champ, confirmé)
 *    (les champs masqués par une condition ne sont pas retournés)
 *
 * VARIANTES
 *    new, add, edit, duplicate, delete, filter, view
 *    Form.new(cfg) / Form.add(cfg) / Form.edit(cfg) / ... ou { variant: 'edit' }
 *    Form.variant('archive', { accent: 'indigo', icon: 'copy', title: 'Archiver', confirmText: 'Archiver' })
 *    Une variante définit : accent (blue|green|amber|red|indigo|gray), icon (plus|pencil|trash|copy|filter|eye|refresh),
 *      title, confirmText, cancelText, showCancel, autoFocus ('confirm'|'cancel'), readonly, requireChange.
 *
 * OPTIONS DU FORMULAIRE
 *    variant, title, message, fields, values, columns ('auto'|1|2|3), size ('sm'|'md'|'lg'|'xl'|'2xl'|'3xl'|'4xl'),
 *    confirmText, cancelText, showCancel, closeOnOverlayClick, closeOnEsc, zIndex, readonly, requireChange,
 *    validateOn ('submit' (défaut) | 'blur'), onChange(name, value, api), onOpen(api),
 *    onSubmit(result, api)
 *      - Si onSubmit est fourni, le formulaire reste ouvert jusqu'à api.close().
 *      - onSubmit peut renvoyer une Promise (spinner sur le bouton). Une erreur { errors: { champ: 'msg' } }
 *        est affichée sur les champs, toute autre erreur dans un bandeau en haut du formulaire.
 *      - api : close(valeur?), setErrors({champ: msg}), clearErrors(), setError('message global'),
 *              setLoading(bool), changes, isDirty, values, get(nom?), set(nom, v), setAll({...}), focus(nom),
 *              disable(nom, bool), regenerate(nom), refresh()
 *    Ctrl/Cmd + Entrée valide le formulaire, même depuis un textarea.
 *
 * MISE EN PAGE (grille de 12 colonnes sur >= sm, 1 colonne sur mobile)
 *    - { type: 'row', fields: [ ... ] } (ou 'group') place plusieurs champs sur la même ligne
 *      (largeur égale par défaut, ajustable avec span / width). Un row accepte showIf / hidden.
 *    - span: 1..12, width: '1/4' | '1/3' | '1/2' | '2/3' | '3/4' | 'full', newLine: true (force un retour à la ligne)
 *    - fullWidth: true (ou textarea / section) : toute la largeur.
 *    - Sans mise en page explicite : au-delà de 4 champs, 2 colonnes automatiques.
 *
 * CHAMPS DÉPENDANTS
 *    Chaque option dynamique accepte : booléen | fonction (values, api) => bool | condition objet | nom de champ (rempli ?)
 *      required / requiredIf   : obligatoire (l'astérisque suit l'état)
 *      showIf (ou hidden)      : visible / masqué (un champ masqué n'est ni validé ni retourné)
 *      disabled / disabledIf   : désactivé
 *      clearOnHide: true       : vide la valeur quand le champ est masqué
 *    Condition objet : { field, eq | ne | in | notIn | gt | gte | lt | lte | empty | truthy | matches }
 *                      ou { all: [...] } | { any: [...] } | { not: cond } ; un tableau = ET logique.
 *      ex : { name: 'siret', showIf: { field: 'type', eq: 'company' }, requiredIf: { field: 'type', eq: 'company' } }
 *    Valeurs dérivées :
 *      from: 'name' | ['a','b']  : recopie (et transforme avec transform(texte, values)) tant que l'utilisateur n'a pas modifié le champ
 *      derive: (values) => valeur : idem, pour un calcul libre (dependsOn: ['a','b'] pour limiter les déclencheurs)
 *      computed: (values) => valeur : toujours recalculé, champ verrouillé (lecture seule)
 *    select dynamique : options: (values) => [...] (dependsOn: 'country' pour limiter les déclencheurs)
 *
 * TYPES DE CHAMPS
 *    Natifs : text, email, password (strength), number, tel, url, date, time, datetime, month, week,
 *             search, textarea, select, checkbox
 *    Form   : phone, slug, radio, switch, range, rating, tags, color, file, otp, money, section
 *    Options communes : name, label, type, value, placeholder, required, errorText, hint, validate(v, all),
 *      min, max, minLength, maxLength, pattern, patternText, counter, autocomplete, matches, options, rows,
 *      prefix / suffix (addon texte pour les champs de saisie), autofocus, format ('lower'|'upper'|'digits'|'slug'|'tags'|fn)
 *    slug    from, prefix, separator ('-'), minLength, maxLength, regenerateOnSourceChange ; bouton si "from"
 *    radio   options:[{value,label,description}|'texte'], layout:'list'|'cards'
 *    range   min, max, step, suffix        rating  max (5)         tags   maxTags, unique, placeholder
 *    file    accept, multiple, maxSize     otp     length (6), numeric (true)
 *    money   min, max, step, suffix ('€')  section title, description
 *    Form.register('monType', { render({id, field, changed, getForm, values}) -> { el, input, getValue, setValue?,
 *                                onError?, noLabel?, hintIndent? }, isEmpty?, validate?, defaults? })
 *
 *  Form.configure({ zIndex, labels: {...} })      Form.slugify(texte, { separator, final })
 */
(function (global) {
    'use strict';

    /* ==========================================================
     * Configuration
     * ========================================================== */
    const CONFIG = { zIndex: 800 };

    const LABELS = {
        close: 'Fermer',
        cancel: 'Annuler',
        confirm: 'Confirmer',
        save: 'Enregistrer',
        required: 'Ce champ est requis.',
        email: 'Adresse e-mail invalide.',
        pattern: 'Format invalide.',
        match: 'Les valeurs ne correspondent pas.',
        minLength: (n) => `${n} caractères minimum.`,
        maxLength: (n) => `${n} caractères maximum.`,
        min: (n) => `La valeur doit être supérieure ou égale à ${n}.`,
        max: (n) => `La valeur doit être inférieure ou égale à ${n}.`,
        showPassword: 'Afficher le mot de passe',
        hidePassword: 'Masquer le mot de passe',
        strength: ['Très faible', 'Faible', 'Moyen', 'Bon', 'Fort'],
        incomplete: 'Code incomplet.',
        fileTooBig: (mo) => `Fichier trop volumineux (max ${mo} Mo).`,
        phone: 'Numéro de téléphone invalide.',
        noChange: 'Aucun changement détecté.',
        genericError: 'Une erreur est survenue.',
        regenerate: 'Régénérer depuis la source',
        noResults: 'Aucun résultat.',
        searchError: 'Recherche impossible.',
        remove: 'Retirer',
        addTo: 'Ajouter à :',
    };

    let zCounter = 0;
    let uid = 0;

    /* ==========================================================
     * Utilitaires
     * ========================================================== */
    const toks = (s) => s.split(/\s+/).filter(Boolean);
    const addCls = (el, s) => el.classList.add(...toks(s));
    const rmCls = (el, s) => el.classList.remove(...toks(s));
    const swapCls = (el, from, to) => { rmCls(el, from); addCls(el, to); };
    const swapBorder = (el, on, okCls, errCls) => swapCls(el, on ? okCls : errCls, on ? errCls : okCls);

    function h(tag, className = '', props = {}) {
        const el = document.createElement(tag);
        if (className) el.className = className;
        for (const [k, v] of Object.entries(props)) {
            if (v === undefined || v === null || v === false) continue;
            if (k === 'text') el.textContent = v;
            else el.setAttribute(k, v === true ? '' : v);
        }
        return el;
    }

    const normOpt = (o) => (o && typeof o === 'object'
        ? { value: o.value, label: o.label ?? o.value, description: o.description }
        : { value: o, label: o });

    const isFilled = (v) => v !== undefined && v !== null && v !== '' && v !== false && !(Array.isArray(v) && v.length === 0);
    const looseEq = (a, b) => a === b || (a !== null && b !== null && a !== undefined && b !== undefined && String(a) === String(b));
    /** Doublons : `unique` (défaut true) ou `allowDuplicates: true`. `caseSensitive` pour tags. */
    const uniqueOf = (f) => !f.allowDuplicates && f.unique !== false;
    const sameTag = (a, b, f) => (f.caseSensitive
        ? String(a) === String(b)
        : String(a).toLowerCase() === String(b).toLowerCase());
    const fold = (s) => String(s ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

    /** Slug : sans accents, minuscules, séparateurs uniques. final=false conserve le séparateur final (frappe en cours). */
    function slugify(s, opts = {}) {
        const final = opts.final !== false;
        const sep = opts.separator ?? '-';
        const base = String(s ?? '')
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .toLowerCase().replace(/œ/g, 'oe').replace(/æ/g, 'ae').replace(/ß/g, 'ss');
        const out = base.split(/[^a-z0-9]+/).filter(Boolean).join(sep);
        return !final && out && /[^a-z0-9]$/.test(base) ? out + sep : out;
    }

    /** Évalue une condition dynamique (booléen | fonction | nom de champ | objet | tableau). */
    function evalCond(c, values, api) {
        if (typeof c === 'function') return !!c(values, api);
        if (typeof c === 'string') return isFilled(values[c]);
        if (Array.isArray(c)) return c.every((x) => evalCond(x, values, api));
        if (c && typeof c === 'object') {
            if (c.all) return c.all.every((x) => evalCond(x, values, api));
            if (c.any) return c.any.some((x) => evalCond(x, values, api));
            if (c.not !== undefined) return !evalCond(c.not, values, api);
            const v = values[c.field];
            if ('eq' in c) return looseEq(v, c.eq);
            if ('ne' in c) return !looseEq(v, c.ne);
            if ('in' in c) return c.in.some((x) => looseEq(v, x));
            if ('notIn' in c) return !c.notIn.some((x) => looseEq(v, x));
            if ('gt' in c) return Number(v) > Number(c.gt);
            if ('gte' in c) return Number(v) >= Number(c.gte);
            if ('lt' in c) return Number(v) < Number(c.lt);
            if ('lte' in c) return Number(v) <= Number(c.lte);
            if ('empty' in c) return !isFilled(v) === !!c.empty;
            if ('truthy' in c) return isFilled(v) === !!c.truthy;
            if ('matches' in c) return new RegExp(c.matches).test(String(v ?? ''));
            return isFilled(v);
        }
        return !!c;
    }

    /* ==========================================================
     * Icônes (Heroicons outline)
     * ========================================================== */
    const P = (d) => `<path stroke-linecap="round" stroke-linejoin="round" d="${d}" />`;
    const PATHS = {
        plus: P('M12 4.5v15m7.5-7.5h-15'),
        pencil: P('M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10'),
        trash: P('M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0'),
        copy: P('M16.5 8.25V6a2.25 2.25 0 00-2.25-2.25H6A2.25 2.25 0 003.75 6v8.25A2.25 2.25 0 006 16.5h2.25m8.25-8.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-7.5A2.25 2.25 0 018.25 18v-1.5m8.25-8.25h-6a2.25 2.25 0 00-2.25 2.25v6'),
        filter: P('M12 3c2.755 0 5.455.232 8.083.678.533.09.917.556.917 1.096v1.044a2.25 2.25 0 01-.659 1.591l-5.432 5.432a2.25 2.25 0 00-.659 1.591v2.927a2.25 2.25 0 01-1.244 2.013L9.75 21v-6.568a2.25 2.25 0 00-.659-1.591L3.659 7.409A2.25 2.25 0 013 5.818V4.774c0-.54.384-1.006.917-1.096A48.32 48.32 0 0112 3z'),
        eye: P('M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z') + P('M15 12a3 3 0 11-6 0 3 3 0 016 0z'),
        eyeOff: P('M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88'),
        refresh: P('M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99'),
    };
    const icon = (name, cls) =>
        `<svg class="${cls}" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">${PATHS[name] || PATHS.pencil}</svg>`;
    const spinner = (cls) =>
        `<svg class="${cls} animate-spin motion-reduce:animate-none" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>`;

    /* ==========================================================
     * Accents et variantes
     * ========================================================== */
    const ACCENTS = {
        blue: { top: 'border-t-blue-500 dark:border-t-blue-400', icon: 'text-blue-500 dark:text-blue-400', btn: 'bg-blue-600 hover:bg-blue-700 focus-visible:ring-blue-500 dark:bg-blue-500 dark:hover:bg-blue-400' },
        green: { top: 'border-t-green-500 dark:border-t-green-400', icon: 'text-green-500 dark:text-green-400', btn: 'bg-green-600 hover:bg-green-700 focus-visible:ring-green-500 dark:bg-green-500 dark:hover:bg-green-400' },
        amber: { top: 'border-t-amber-500 dark:border-t-amber-400', icon: 'text-amber-500 dark:text-amber-400', btn: 'bg-amber-600 hover:bg-amber-700 focus-visible:ring-amber-500 dark:bg-amber-600 dark:hover:bg-amber-500' },
        red: { top: 'border-t-red-500 dark:border-t-red-400', icon: 'text-red-500 dark:text-red-400', btn: 'bg-red-600 hover:bg-red-700 focus-visible:ring-red-500 dark:bg-red-500 dark:hover:bg-red-400' },
        indigo: { top: 'border-t-indigo-500 dark:border-t-indigo-400', icon: 'text-indigo-500 dark:text-indigo-400', btn: 'bg-indigo-600 hover:bg-indigo-700 focus-visible:ring-indigo-500 dark:bg-indigo-500 dark:hover:bg-indigo-400' },
        gray: { top: 'border-t-gray-400 dark:border-t-gray-500', icon: 'text-gray-500 dark:text-gray-400', btn: 'bg-gray-800 hover:bg-gray-900 focus-visible:ring-gray-500 dark:bg-gray-600 dark:hover:bg-gray-500' },
    };

    const VARIANTS = {
        new: { accent: 'green', icon: 'plus', title: 'Nouveau', confirmText: 'Créer' },
        add: { accent: 'blue', icon: 'plus', title: 'Ajouter', confirmText: 'Ajouter' },
        edit: { accent: 'amber', icon: 'pencil', title: 'Modifier', confirmText: 'Enregistrer', requireChange: true },
        duplicate: { accent: 'indigo', icon: 'copy', title: 'Dupliquer', confirmText: 'Dupliquer' },
        delete: { accent: 'red', icon: 'trash', title: 'Supprimer', confirmText: 'Supprimer', autoFocus: 'cancel' },
        filter: { accent: 'gray', icon: 'filter', title: 'Filtrer', confirmText: 'Appliquer' },
        view: { accent: 'gray', icon: 'eye', title: 'Détails', confirmText: 'Fermer', showCancel: false, readonly: true },
    };

    const FORM_DEFAULTS = {
        variant: 'add',
        title: null,
        message: '',
        fields: [],
        values: null,
        columns: 'auto',
        size: null,
        confirmText: null,
        cancelText: null,
        showCancel: true,
        closeOnOverlayClick: false,
        closeOnEsc: true,
        autoFocus: 'confirm',
        readonly: false,
        requireChange: false,
        validateOn: 'submit',
        zIndex: null,
        onSubmit: null,
    };

    /* ==========================================================
     * Classes de base
     * ========================================================== */
    const HIDDEN = 'opacity-0 translate-y-2 scale-95';
    const SHOWN = 'opacity-100 translate-y-0 scale-100';
    const OVERLAY_BASE = 'fixed inset-0 flex items-center justify-center p-4 transition duration-200 motion-reduce:transition-none';
    const OVERLAY_HIDDEN = 'bg-gray-900/0 backdrop-blur-none dark:bg-black/0';
    const OVERLAY_SHOWN = 'bg-gray-900/50 backdrop-blur-sm dark:bg-black/60';
    const DIALOG_BASE = 'max-h-full w-full max-w-md overflow-y-auto rounded-2xl border border-t-4 border-gray-200 bg-white p-5 text-gray-800 shadow-2xl ring-1 ring-black/5 outline-none transition duration-200 motion-reduce:transition-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 dark:ring-white/10';
    const BTN_CANCEL = 'rounded-lg bg-gray-100 px-3.5 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-400 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-gray-700 dark:text-gray-200 dark:ring-offset-gray-800 dark:hover:bg-gray-600';
    const BTN_CONFIRM = 'inline-flex items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-medium text-white shadow-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 dark:ring-offset-gray-800';
    const FOCUSABLE = 'button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])';

    // Grille 12 colonnes (classes écrites en entier pour Tailwind)
    const SPAN = {
        1: 'sm:col-span-1', 2: 'sm:col-span-2', 3: 'sm:col-span-3', 4: 'sm:col-span-4', 5: 'sm:col-span-5', 6: 'sm:col-span-6',
        7: 'sm:col-span-7', 8: 'sm:col-span-8', 9: 'sm:col-span-9', 10: 'sm:col-span-10', 11: 'sm:col-span-11', 12: 'sm:col-span-12',
    };
    const WIDTHS = { '1/4': 3, '1/3': 4, '1/2': 6, '2/3': 8, '3/4': 9, full: 12 };
    const SIZES = { sm: 'max-w-sm', md: 'max-w-md', lg: 'max-w-lg', xl: 'max-w-xl', '2xl': 'max-w-2xl', '3xl': 'max-w-3xl', '4xl': 'max-w-4xl' };
    const COLS_WIDTH = { 1: 'max-w-md', 2: 'max-w-2xl', 3: 'max-w-4xl' };
    const COLS_THRESHOLD = 4; // au-delà de ce nombre de champs (sans mise en page explicite) : 2 colonnes

    const INPUT = 'block w-full rounded-lg border bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 shadow-sm outline-none transition focus:ring-2 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-gray-900 dark:text-gray-100 dark:placeholder-gray-500 dark:[color-scheme:dark]';
    const OK = 'border-gray-300 focus:border-blue-500 focus:ring-blue-500/30 dark:border-gray-600 dark:focus:border-blue-400 dark:focus:ring-blue-400/30';
    const ERR = 'border-red-500 focus:border-red-500 focus:ring-red-500/30 dark:border-red-400 dark:focus:border-red-400 dark:focus:ring-red-400/30';
    const BOX_OK = 'border-gray-300 dark:border-gray-600';
    const BOX_ERR = 'border-red-500 dark:border-red-400';
    const FOCUS_RING = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:focus-visible:ring-blue-400';
    const TOGGLE_CLS = 'absolute inset-y-0 right-0 flex items-center rounded-r-lg px-3 text-gray-400 transition hover:text-gray-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:text-gray-500 dark:hover:text-gray-300';
    const ADDON = 'inline-flex shrink-0 items-center border border-gray-300 bg-gray-50 px-3 text-sm text-gray-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-400';
    const SEG_BASE = 'h-1 flex-1 rounded-full transition-colors';
    const SEG_EMPTY = 'bg-gray-200 dark:bg-gray-700';
    const STRENGTH_CLS = ['bg-red-500 dark:bg-red-400', 'bg-orange-500 dark:bg-orange-400', 'bg-lime-500 dark:bg-lime-400', 'bg-green-500 dark:bg-green-400'];
    const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    const PHONE_RE = /^\+?[0-9\s().-]{6,20}$/;

    /* ==========================================================
     * Formats de saisie
     * ========================================================== */
    const FORMATS = {
        tags(v, { field, final }) {
            const sep = field.separator ?? ', ';
            const trailing = /[\s,;]$/.test(v);
            let parts = v.split(field.split || /[\s,;]+/).filter(Boolean);
            // dédoublonnage seulement à la fin (sinon on bloque la frappe de "a, ab")
            if (final && uniqueOf(field)) {
                parts = parts.filter((p, i) => parts.findIndex((q) => sameTag(q, p, field)) === i);
            }
            let out = parts.join(sep);
            if (trailing && !final && parts.length) out += sep;
            return out;
        },
        lower: (v) => v.toLowerCase(),
        upper: (v) => v.toUpperCase(),
        digits: (v) => v.replace(/\D/g, ''),
        slug: (v, { field, final }) => slugify(v, { final, separator: (field && field.separator) || '-' }),
    };

    function reformat(input, fmt, field, final) {
        const old = input.value;
        const pos = input.selectionStart;
        const next = fmt(old, { field, final });
        if (next === old) return;
        const atEnd = pos === null || pos >= old.length;
        input.value = next;
        if (document.activeElement === input) {
            const p = atEnd ? next.length : Math.max(0, Math.min(next.length, pos + next.length - old.length));
            try { input.setSelectionRange(p, p); } catch (e) { /* type sans sélection */ }
        }
    }

    /* ==========================================================
     * Types de champs « Form » (registre extensible)
     * ========================================================== */
    const TYPES = {};
    const NATIVE = new Set(['text', 'email', 'password', 'number', 'tel', 'url', 'date', 'time', 'datetime-local', 'month', 'week', 'search', 'textarea', 'select', 'checkbox']);
    const ALIASES = { datetime: 'datetime-local' };

    TYPES.section = {
        defaults: { fullWidth: true },
        render({ field }) {
            const el = h('div', 'border-b border-gray-200 pb-1.5 pt-2 dark:border-gray-700');
            el.appendChild(h('p', 'text-sm font-semibold text-gray-900 dark:text-white', { text: field.title || field.label || '' }));
            if (field.description) el.appendChild(h('p', 'mt-0.5 text-xs text-gray-500 dark:text-gray-400', { text: field.description }));
            return { el, noLabel: true, getValue: () => '' };
        },
    };

    TYPES.slug = {
        render({ id, field, changed, getForm }) {
            const sep = field.separator || '-';
            const fmtOpts = (final) => ({ final, separator: sep });
            const prefix = field.prefix ? h('span', `${ADDON} rounded-l-lg border-r-0 font-mono`, { text: field.prefix }) : null;
            const row = h('div', 'flex');
            const cls = `${INPUT} ${OK} min-w-0 flex-1 font-mono${prefix ? ' !rounded-l-none' : ''}${field.from ? ' !rounded-r-none' : ''}`;
            const input = h('input', cls, { type: 'text', id, spellcheck: 'false', autocomplete: 'off', autocapitalize: 'off' });
            if (field.placeholder) input.placeholder = field.placeholder;
            if (field.maxLength) input.maxLength = field.maxLength;
            if (field.value) input.value = String(field.value);
            input.addEventListener('input', () => {
                const old = input.value;
                reformat(input, (v) => slugify(v, fmtOpts(false)), field, false);
                if (old !== input.value || true) changed();
            });
            input.addEventListener('blur', () => reformat(input, (v) => slugify(v, fmtOpts(true)), field, true));
            if (prefix) row.appendChild(prefix);
            row.appendChild(input);
            if (field.from) {
                const btn = h('button', `${ADDON} rounded-r-lg border-l-0 transition hover:text-gray-700 disabled:cursor-not-allowed disabled:opacity-60 dark:hover:text-gray-200 ${FOCUS_RING}`, { type: 'button', title: LABELS.regenerate, 'aria-label': LABELS.regenerate });
                btn.innerHTML = icon('refresh', 'h-4 w-4');
                btn.addEventListener('click', () => { const f = getForm(); if (f) f.regenerate(field.name); input.focus(); });
                row.appendChild(btn);
            }
            return {
                el: row, input,
                getValue: () => slugify(input.value, fmtOpts(true)),
                setValue: (v) => { input.value = slugify(String(v ?? ''), fmtOpts(true)); },
                onError: (e) => swapBorder(input, e, OK, ERR),
            };
        },
        validate: (v, all, field) => (field.minLength && v.length < field.minLength ? LABELS.minLength(field.minLength) : undefined),
    };

    TYPES.radio = {
        render({ id, field, changed }) {
            const cards = field.layout === 'cards';
            const wrap = h('div', `${cards ? 'grid gap-2 sm:grid-cols-2' : 'space-y-2'} rounded-lg`, { role: 'radiogroup' });
            const groupName = `${id}-grp`;
            const inputs = [];
            (field.options || []).map(normOpt).forEach((o, i) => {
                const label = h('label', 'flex cursor-pointer items-start gap-2.5 rounded-lg border border-gray-200 p-2.5 text-sm text-gray-700 transition hover:bg-gray-50 has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-700/40 dark:has-[:checked]:border-blue-400 dark:has-[:checked]:bg-blue-500/10');
                const input = h('input', 'mt-0.5 h-4 w-4 shrink-0 border-gray-300 text-blue-600 focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900', { type: 'radio', name: groupName, value: o.value });
                if (i === 0) input.id = id;
                if (field.value !== undefined && field.value !== null && String(field.value) === String(o.value)) input.checked = true;
                input.addEventListener('change', changed);
                const txt = h('span', 'min-w-0');
                txt.appendChild(h('span', 'block font-medium', { text: o.label }));
                if (o.description) txt.appendChild(h('span', 'mt-0.5 block text-xs text-gray-500 dark:text-gray-400', { text: o.description }));
                label.append(input, txt);
                wrap.appendChild(label);
                inputs.push(input);
            });
            return {
                el: wrap,
                input: inputs[0] || wrap,
                getValue: () => (inputs.find((i) => i.checked) || { value: '' }).value,
                setValue: (v) => { inputs.forEach((i) => { i.checked = String(i.value) === String(v); }); },
                onError: (on) => { wrap.classList.toggle('ring-2', on); wrap.classList.toggle('ring-red-500', on); },
            };
        },
    };

    TYPES.switch = {
        defaults: { value: false },
        isEmpty: (v) => v === false,
        render({ id, field, changed }) {
            let on = !!field.value;
            const row = h('div', 'flex items-start gap-3');
            const btn = h('button', `relative mt-0.5 inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors disabled:cursor-not-allowed disabled:opacity-60 ${FOCUS_RING} focus-visible:ring-offset-2 dark:focus-visible:ring-offset-gray-800`, { type: 'button', role: 'switch', id });
            const knob = h('span', 'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition');
            btn.appendChild(knob);
            const paint = () => {
                btn.setAttribute('aria-checked', String(on));
                rmCls(btn, 'bg-blue-600 dark:bg-blue-500 bg-gray-300 dark:bg-gray-600');
                addCls(btn, on ? 'bg-blue-600 dark:bg-blue-500' : 'bg-gray-300 dark:bg-gray-600');
                rmCls(knob, 'translate-x-5 translate-x-0');
                addCls(knob, on ? 'translate-x-5' : 'translate-x-0');
            };
            btn.addEventListener('click', () => { on = !on; paint(); changed(); });
            row.append(btn, h('label', 'cursor-pointer text-sm text-gray-700 dark:text-gray-200', { for: id, text: field.label || '' }));
            paint();
            return {
                el: row, input: btn, noLabel: true,
                hintIndent: 'pl-14', // w-11 (2.75rem) + gap-3 (0.75rem) : hint aligné sous le libellé
                getValue: () => on,
                setValue: (v) => { on = !!v; paint(); },
                onError: (e) => { btn.classList.toggle('ring-2', e); btn.classList.toggle('ring-red-500', e); },
            };
        },
    };

    TYPES.range = {
        render({ id, field, changed }) {
            const min = field.min ?? 0;
            const max = field.max ?? 100;
            const row = h('div', 'flex items-center gap-3');
            const input = h('input', `h-2 w-full cursor-pointer rounded-lg accent-blue-600 disabled:cursor-not-allowed disabled:opacity-60 dark:accent-blue-400 ${FOCUS_RING}`, { type: 'range', id, min, max, step: field.step ?? 1 });
            input.value = field.value ?? min;
            const out = h('output', 'min-w-[3rem] text-right text-sm font-semibold tabular-nums text-gray-800 dark:text-gray-100');
            const refresh = () => { out.textContent = input.value + (field.suffix ? ` ${field.suffix}` : ''); };
            input.addEventListener('input', () => { refresh(); changed(); });
            refresh();
            row.append(input, out);
            return {
                el: row, input,
                getValue: () => Number(input.value),
                setValue: (v) => { input.value = v; refresh(); },
            };
        },
    };

    TYPES.rating = {
        isEmpty: (v) => !v,
        render({ id, field, changed }) {
            const max = field.max || 5;
            let val = Number(field.value) || 0;
            let hover = 0;
            const wrap = h('div', 'inline-flex gap-0.5 rounded-lg', { role: 'radiogroup' });
            const stars = [];
            const paint = () => {
                const shown = hover || val;
                stars.forEach((s, i) => {
                    rmCls(s, 'text-amber-400 dark:text-amber-300 text-gray-300 dark:text-gray-600');
                    addCls(s, i < shown ? 'text-amber-400 dark:text-amber-300' : 'text-gray-300 dark:text-gray-600');
                    s.setAttribute('aria-checked', String(i + 1 === val));
                });
            };
            for (let i = 1; i <= max; i++) {
                const s = h('button', `rounded px-0.5 text-2xl leading-none transition disabled:cursor-not-allowed ${FOCUS_RING}`, { type: 'button', role: 'radio', 'aria-label': `${i}/${max}`, text: '★' });
                if (i === 1) s.id = id;
                s.addEventListener('click', () => { val = val === i ? 0 : i; paint(); changed(); });
                s.addEventListener('mouseenter', () => { hover = i; paint(); });
                s.addEventListener('mouseleave', () => { hover = 0; paint(); });
                stars.push(s);
                wrap.appendChild(s);
            }
            paint();
            return {
                el: wrap, input: stars[0],
                getValue: () => val,
                setValue: (v) => { val = Number(v) || 0; paint(); },
                onError: (e) => { wrap.classList.toggle('ring-2', e); wrap.classList.toggle('ring-red-500', e); },
            };
        },
    };

    TYPES.tags = {
        isEmpty: (v) => !v || v.length === 0,
        render({ id, field, changed }) {
            const list = Array.isArray(field.value) ? [...field.value] : [];
            const unique = uniqueOf(field);
            const box = h('div', `flex flex-wrap items-center gap-1.5 rounded-lg border bg-white p-1.5 shadow-sm transition focus-within:ring-2 focus-within:ring-blue-500/30 dark:bg-gray-900 ${BOX_OK}`);
            const input = h('input', 'min-w-[8rem] flex-1 border-0 bg-transparent px-1.5 py-1 text-sm text-gray-900 placeholder-gray-400 outline-none disabled:cursor-not-allowed dark:text-gray-100 dark:placeholder-gray-500', { type: 'text', id, placeholder: field.placeholder || 'Ajouter puis Entrée' });
            const renderChips = () => {
                box.querySelectorAll('[data-chip]').forEach((c) => c.remove());
                list.forEach((t, i) => {
                    const chip = h('span', 'inline-flex items-center gap-1 rounded-md bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-500/20 dark:text-blue-200', { 'data-chip': '' });
                    chip.appendChild(h('span', '', { text: t }));
                    const x = h('button', `rounded text-blue-500 hover:text-blue-800 disabled:cursor-not-allowed dark:text-blue-300 dark:hover:text-white ${FOCUS_RING}`, { type: 'button', 'aria-label': `Retirer ${t}`, text: '×' });
                    if (input.disabled) x.disabled = true;
                    x.addEventListener('click', () => { list.splice(i, 1); renderChips(); changed(); input.focus(); });
                    chip.appendChild(x);
                    box.insertBefore(chip, input);
                });
            };
            const seps = field.separators || ['Enter', ','];
            const splitRe = seps.includes(' ') ? /[,\s]+/ : /,/;
            const commit = () => {
                const parts = input.value.split(splitRe).map((s) => s.trim()).filter(Boolean);
                input.value = '';
                parts.forEach((p) => {
                    if (field.maxTags && list.length >= field.maxTags) return;
                    if (unique && list.some((x) => sameTag(x, p, field))) return;
                    list.push(p);
                });
                renderChips();
                changed();
            };
            input.addEventListener('keydown', (e) => {
                if (seps.includes(e.key)) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (input.value.trim()) commit();
                } else if (e.key === 'Backspace' && !input.value && list.length) {
                    list.pop(); renderChips(); changed();
                }
            });
            input.addEventListener('blur', () => { if (input.value.trim()) commit(); });
            box.addEventListener('mousedown', (e) => { if (e.target === box) { e.preventDefault(); input.focus(); } });
            box.appendChild(input);
            renderChips();
            return {
                el: box,
                input,
                getValue: () => [...list],
                setValue: (v) => {
                    list.splice(0, list.length, ...(Array.isArray(v) ? v : String(v || '').split(',').map((s) => s.trim()).filter(Boolean)));
                    renderChips();
                },
                onError: (e) => swapBorder(box, e, BOX_OK, BOX_ERR),
            };
        },
    };

    TYPES.color = {
        render({ id, field, changed }) {
            const row = h('div', 'flex items-center gap-3');
            const input = h('input', `h-9 w-14 cursor-pointer rounded-lg border bg-white p-1 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-gray-900 ${BOX_OK} ${FOCUS_RING}`, { type: 'color', id });
            input.value = field.value || '#3b82f6';
            const hex = h('span', 'font-mono text-sm uppercase text-gray-600 dark:text-gray-300');
            const refresh = () => { hex.textContent = input.value; };
            input.addEventListener('input', () => { refresh(); changed(); });
            refresh();
            row.append(input, hex);
            return {
                el: row, input,
                getValue: () => input.value,
                setValue: (v) => { input.value = v; refresh(); },
            };
        },
    };

    TYPES.file = {
        render({ id, field, changed }) {
            const input = h('input', `block w-full cursor-pointer rounded-lg border bg-white text-sm text-gray-700 shadow-sm file:mr-3 file:cursor-pointer file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-gray-700 hover:file:bg-gray-200 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-gray-900 dark:text-gray-200 dark:file:bg-gray-700 dark:file:text-gray-100 dark:hover:file:bg-gray-600 ${BOX_OK} ${FOCUS_RING}`, { type: 'file', id });
            if (field.accept) input.accept = field.accept;
            if (field.multiple) input.multiple = true;
            input.addEventListener('change', changed);
            return {
                el: input, input,
                getValue() {
                    const files = Array.from(input.files || []);
                    if (!files.length) return '';
                    return field.multiple ? files : files[0];
                },
                onError: (e) => swapBorder(input, e, BOX_OK, BOX_ERR),
            };
        },
        validate(v, all, field) {
            if (!field.maxSize) return undefined;
            const big = (Array.isArray(v) ? v : [v]).find((f) => f && f.size > field.maxSize);
            return big ? LABELS.fileTooBig((field.maxSize / 1048576).toFixed(field.maxSize < 1048576 ? 2 : 0)) : undefined;
        },
    };

    TYPES.otp = {
        render({ id, field, changed }) {
            const n = field.length || 6;
            const numeric = field.numeric !== false;
            const row = h('div', 'flex gap-2');
            const boxes = [];
            const clean = (s) => (numeric ? s.replace(/\D/g, '') : s.replace(/[^A-Za-z0-9]/g, ''));
            const fill = (from, text) => {
                const chars = clean(text).slice(0, n - from).split('');
                chars.forEach((c, k) => { boxes[from + k].value = c; });
                boxes[Math.min(from + chars.length, n - 1)].focus();
                changed();
            };
            for (let i = 0; i < n; i++) {
                const b = h('input', `h-11 w-10 rounded-lg border bg-white text-center text-lg font-semibold text-gray-900 shadow-sm outline-none transition focus:ring-2 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-gray-900 dark:text-gray-100 ${OK}`, { type: 'text', inputmode: numeric ? 'numeric' : 'text', maxlength: '1', autocomplete: i === 0 ? 'one-time-code' : 'off', 'aria-label': `Caractère ${i + 1}` });
                if (i === 0) b.id = id;
                b.addEventListener('input', () => {
                    const v = clean(b.value);
                    if (v.length > 1) { b.value = ''; fill(i, v); return; }
                    b.value = v;
                    if (v && i < n - 1) boxes[i + 1].focus();
                    changed();
                });
                b.addEventListener('keydown', (e) => {
                    if (e.key === 'Backspace' && !b.value && i > 0) { boxes[i - 1].focus(); boxes[i - 1].value = ''; }
                    else if (e.key === 'ArrowLeft' && i > 0) boxes[i - 1].focus();
                    else if (e.key === 'ArrowRight' && i < n - 1) boxes[i + 1].focus();
                });
                b.addEventListener('paste', (e) => { e.preventDefault(); fill(i, (e.clipboardData || global.clipboardData).getData('text')); });
                b.addEventListener('focus', () => b.select());
                boxes.push(b);
                row.appendChild(b);
            }
            if (field.value) fill(0, String(field.value));
            return { el: row, input: boxes[0], getValue: () => boxes.map((b) => b.value).join(''), onError: (e) => boxes.forEach((b) => swapBorder(b, e, OK, ERR)) };
        },
        validate: (v, all, field) => (v.length < (field.length || 6) ? LABELS.incomplete : undefined),
    };

    TYPES.money = {
        render({ id, field, changed }) {
            const holder = h('div', 'relative');
            const input = h('input', `${INPUT} ${OK} pr-12 text-right tabular-nums`, { type: 'number', id, inputmode: 'decimal', step: field.step ?? '0.01' });
            if (field.min !== undefined) input.min = field.min;
            if (field.max !== undefined) input.max = field.max;
            if (field.placeholder) input.placeholder = field.placeholder;
            if (field.value !== undefined && field.value !== null) input.value = field.value;
            const suffix = h('span', 'pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3 text-sm text-gray-500 dark:text-gray-400', { text: field.suffix ?? '€' });
            input.addEventListener('input', changed);
            holder.append(input, suffix);
            return {
                el: holder, input,
                getValue: () => (input.value === '' ? '' : Number(input.value)),
                setValue: (v) => { input.value = v ?? ''; },
                onError: (e) => swapBorder(input, e, OK, ERR),
            };
        },
        validate(v, all, field) {
            if (field.min !== undefined && v < Number(field.min)) return LABELS.min(field.min);
            if (field.max !== undefined && v > Number(field.max)) return LABELS.max(field.max);
            return undefined;
        },
    };
    /**
     * Recherche avec résultats affichés (autocomplétion).
     *  source        : tableau | (query, values, api) => tableau | Promise<tableau>
     *  minChars (1), limit (8), debounce (250 si source fonction, sinon 0), filter:false (pas de filtre local)
     *  map(item)     : adapte un résultat brut vers { value|id, label|name, description, disabled }
     *  renderItem(raw, { query, item, selectable, highlight }) -> Node | string (string = texte, jamais du HTML)
     *  selectable    : true (défaut) | false -> si false, résultats affichés seulement ; la valeur du champ = texte saisi
     *  Stockage de la sélection (si selectable) :
     *    - par défaut : dans le champ lui-même (valeur = value de l'élément)
     *      multiple: true -> liste de sélection (puces), valeur = tableau ; maxItems ; renderSelected(raw)
     *      valueAs: 'item' -> la valeur est l'objet brut au lieu de item.value
     *    - target: 'nomDuChamp' -> envoie l'élément vers un autre champ (tableau => ajout, sinon remplacement)
     *      targetValue(raw, item) pour choisir ce qui est envoyé (défaut : le libellé)
     *      target: (raw, api, item) => {...} -> stockage libre (ex: panier géré par vos soins)
     *    clearOnSelect (true), onSelect(raw, api, item), unique / allowDuplicates, labelOf(value) (valeur initiale)
     */
    TYPES.lookup = {
        isEmpty: (v) => (Array.isArray(v) ? v.length === 0 : v === '' || v === null || v === undefined),
        render({ id, field, changed, getForm }) {
            const selectable = field.selectable !== false; const targetsMap = field.targets && typeof field.targets === 'object' && !Array.isArray(field.targets) ? field.targets : null;
            const hasTarget = selectable && !!(targetsMap || (field.target !== undefined && field.target !== null));
            const dynamic = hasTarget && !!(targetsMap || field.target === '@focus' || Array.isArray(field.target));
            const candidates = targetsMap ? Object.keys(targetsMap) : (Array.isArray(field.target) ? field.target : null);
            const own = selectable && !hasTarget;
            const multiple = own && !!field.multiple;

            const BASE = { name: undefined, over: {} };
            /** Option effective : surcharge de la cible, sinon option du champ de recherche. */
            const opt = (c, k) => (c.over[k] !== undefined ? c.over[k] : field[k]);
            /** Cible courante + sa configuration propre. */
            const resolve = () => {
                const f = getForm();
                let name;
                if (dynamic) {
                    name = f ? f.lastFocused(field.name, candidates) : undefined;
                    if (name === undefined) name = field.fallbackTarget ?? (candidates ? candidates[0] : undefined);
                } else if (typeof field.target === 'string') name = field.target;
                let over = null;
                if (name !== undefined) {
                    over = (targetsMap && targetsMap[name]) || null;
                    if (!over) { const tf = f && f.field(name); over = (tf && tf.lookup) || null; }
                }
                return { name, over: over || {} };
            };

            let marked = null;
            const mark = (on) => {
                if (!dynamic || field.showTarget === false) return;
                const MARK = 'rounded-lg ring-2 ring-blue-400/50 ring-offset-4 ring-offset-white dark:ring-offset-gray-800';
                if (marked) { rmCls(marked, MARK); marked = null; }
                if (!on) return;
                const f = getForm();
                const n = resolve().name;
                const el = n && f && f.el(n);
                if (el) { addCls(el, MARK); marked = el; }
            };
            const unique = uniqueOf(field);
            const clearOnSelect = field.clearOnSelect !== false;
            const listId = `${id}-list`;
            const toItem = (raw, c = BASE) => {
                const map = opt(c, 'map');
                const m = map ? map(raw) : raw;
                if (m !== null && typeof m === 'object') {
                    return { value: m.value ?? m.id ?? m.label, label: String(m.label ?? m.name ?? m.value ?? ''), description: m.description, disabled: !!m.disabled, raw, cfg: c };
                }
                return { value: m, label: String(m ?? ''), raw, cfg: c };
            };
            const fromValue = (v) => (v !== null && typeof v === 'object'
                ? toItem(v)
                : { value: v, label: String(field.labelOf ? field.labelOf(v) : v), raw: v, cfg: BASE });
            const outValue = (it) => (field.valueAs === 'item' ? it.raw : it.value);

            let selected = [];
            let results = [];
            let rows = [];
            let active = -1;
            let reqId = 0;
            let timer = null;
            let query = '';
            let cur = null;
            const root = h('div');
            const holder = h('div', 'relative');
            const input = h('input', `${INPUT} ${OK} pr-9`, {
                type: 'text', id, role: 'combobox', 'aria-autocomplete': 'list', 'aria-expanded': 'false',
                'aria-controls': listId, autocomplete: 'off', spellcheck: 'false',
            });
            if (field.placeholder) input.placeholder = field.placeholder;
            if (field.maxLength) input.maxLength = field.maxLength;
            const busy = h('span', 'pointer-events-none absolute inset-y-0 right-0 hidden items-center pr-3 text-gray-400');
            busy.innerHTML = spinner('h-4 w-4');
            const panel = h('ul', 'mt-1 hidden max-h-56 overflow-y-auto rounded-lg border border-gray-200 bg-white py-1 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900', { id: listId, role: 'listbox' });
            const chips = h('div', 'mt-2 hidden flex-wrap gap-1.5');
            holder.append(input, busy);
            const caption = h('p', 'mt-1 hidden text-xs text-gray-500 dark:text-gray-400');
            root.append(holder, caption, panel, chips);

            /** Applique le contexte d'une cible (placeholder, légende). Renvoie true si la cible a changé. */
            const setCtx = (c) => {
                if (cur && cur.name === c.name) return false;
                cur = c;
                input.placeholder = opt(c, 'placeholder') || '';
                const f = getForm();
                const tf = c.name !== undefined && f ? f.field(c.name) : null;
                const lbl = opt(c, 'targetLabel') ?? (tf && tf.label);
                const show = dynamic && field.showTarget !== false && !!lbl;
                caption.textContent = show ? `${LABELS.addTo} ${lbl}` : '';
                caption.classList.toggle('hidden', !show);
                return true;
            };
            panel.addEventListener('mousedown', (e) => e.preventDefault()); // garde le focus dans l'input

            const setBusy = (on) => {
                busy.classList.toggle('hidden', !on);
                busy.classList.toggle('flex', on);
                input.setAttribute('aria-busy', String(on));
            };
            const showPanel = (on) => {
                panel.classList.toggle('hidden', !on);
                input.setAttribute('aria-expanded', String(on));
                if (!on) input.removeAttribute('aria-activedescendant');
            };
            const paintActive = () => {
                rows.forEach((li, k) => {
                    const on = k === active;
                    li.classList.toggle('bg-blue-50', on);
                    li.classList.toggle('dark:bg-blue-500/10', on);
                    li.setAttribute('aria-selected', String(on));
                    if (on) { input.setAttribute('aria-activedescendant', li.id); li.scrollIntoView({ block: 'nearest' }); }
                });
                if (active < 0) input.removeAttribute('aria-activedescendant');
            };

            /** Surligne la requête dans un texte (nœuds DOM, jamais de HTML). */
            const highlight = (text, q = query) => {
                const frag = document.createDocumentFragment();
                const t = String(text ?? '');
                const f = fold(t);
                const qf = fold(String(q).trim());
                const at = qf && f.length === t.length ? f.indexOf(qf) : -1;
                if (at < 0) { frag.appendChild(document.createTextNode(t)); return frag; }
                frag.appendChild(document.createTextNode(t.slice(0, at)));
                frag.appendChild(h('mark', 'rounded bg-yellow-200 px-0.5 text-inherit dark:bg-yellow-500/30', { text: t.slice(at, at + qf.length) }));
                frag.appendChild(document.createTextNode(t.slice(at + qf.length)));
                return frag;
            };
            const defaultRow = (it) => {
                const box = h('div');
                const l = h('div', 'font-medium');
                l.appendChild(highlight(it.label));
                box.appendChild(l);
                if (it.description) box.appendChild(h('div', 'text-xs text-gray-500 dark:text-gray-400', { text: it.description }));
                return box;
            };

            const canPick = (it) => selectable && !it.disabled && !input.disabled;

            const renderResults = (message) => {
                panel.textContent = '';
                rows = [];
                active = -1;
                if (message) {
                    panel.appendChild(h('li', 'px-3 py-2 text-xs text-gray-500 dark:text-gray-400', { role: 'presentation', text: message }));
                    showPanel(true);
                    return;
                }
                results.forEach((it, i) => {
                    const pickable = selectable && !it.disabled;
                    const li = h('li', `px-3 py-2 text-gray-800 dark:text-gray-100 ${pickable ? 'cursor-pointer' : 'cursor-default'}${it.disabled ? ' opacity-50' : ''}`, {
                        role: 'option', id: `${id}-opt${i}`, 'aria-selected': 'false', 'aria-disabled': it.disabled ? 'true' : null,
                    });
                    const ri = opt(it.cfg, 'renderItem');
                    const content = ri
                        ? ri(it.raw, { query, item: it, selectable, highlight, target: it.cfg.name })
                        : defaultRow(it);
                    if (content instanceof Node) li.appendChild(content);
                    else if (content !== undefined && content !== null) li.textContent = String(content);
                    if (pickable) {
                        li.addEventListener('click', () => choose(it));
                        li.addEventListener('mouseenter', () => { active = i; paintActive(); });
                    }
                    rows.push(li);
                    panel.appendChild(li);
                });
                showPanel(true);
            };

            const renderChips = () => {
                chips.textContent = '';
                const on = multiple && selected.length > 0;
                chips.classList.toggle('hidden', !on);
                chips.classList.toggle('flex', on);
                selected.forEach((it, i) => {
                    const chip = h('span', 'inline-flex items-center gap-1 rounded-md bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-500/20 dark:text-blue-200');
                    const content = field.renderSelected ? field.renderSelected(it.raw, { item: it }) : it.label;
                    if (content instanceof Node) chip.appendChild(content);
                    else chip.appendChild(h('span', '', { text: String(content ?? '') }));
                    const x = h('button', `rounded text-blue-500 hover:text-blue-800 disabled:cursor-not-allowed dark:text-blue-300 dark:hover:text-white ${FOCUS_RING}`, { type: 'button', 'aria-label': `${LABELS.remove} ${it.label}`, text: '×' });
                    if (input.disabled) x.disabled = true;
                    x.addEventListener('click', () => { selected.splice(i, 1); renderChips(); changed(); input.focus(); });
                    chip.appendChild(x);
                    chips.appendChild(chip);
                });
            };

            function choose(it) {
                if (!canPick(it)) return;
                const f = getForm();
                const c = it.cfg || BASE;
                if (hasTarget) {
                    if (typeof field.target === 'function') field.target(it.raw, f, it);
                    else if (f && c.name !== undefined) {
                        const tv = opt(c, 'targetValue');
                        const val = tv ? tv(it.raw, it) : it.label;
                        const tf = (f && f.field(c.name)) || field;
                        const cur2 = f.get(c.name);
                        if (Array.isArray(cur2)) {
                            if (!uniqueOf(tf) || !cur2.some((x) => looseEq(x, val))) f.set(c.name, [...cur2, val]);
                        } else f.set(c.name, val);
                        f.refresh();
                    }
                } else if (multiple) {
                    if (field.maxItems && selected.length >= field.maxItems) return;
                    if (unique && selected.some((s) => looseEq(s.value, it.value))) return;
                    selected.push(it);
                    renderChips();
                } else {
                    selected = [it];
                }
                if (own && !multiple) input.value = it.label;
                else if (clearOnSelect) input.value = '';
                reqId++;
                setBusy(false);
                results = [];
                showPanel(false);
                changed();
                const onSel = opt(c, 'onSelect');
                if (typeof onSel === 'function') onSel(it.raw, f, it);
            }

            const doSearch = async () => {
                const c = resolve();
                setCtx(c);
                const q = input.value;
                query = q;
                const my = ++reqId;
                const src = opt(c, 'source');
                if (q.trim().length < (opt(c, 'minChars') ?? 1)) { setBusy(false); results = []; showPanel(false); return; }
                setBusy(true);
                let list;
                try {
                    if (typeof src === 'function') { const f = getForm(); list = await src(q, f ? f.get() : {}, f, { target: c.name }); }
                    else if (opt(c, 'filter') === false) list = src || [];
                    else {
                        const qf = fold(q.trim());
                        list = (src || []).filter((raw) => { const n = toItem(raw, c); return fold(`${n.label} ${n.description || ''}`).includes(qf); });
                    }
                } catch (e) {
                    if (my !== reqId) return;
                    setBusy(false);
                    results = [];
                    if (document.activeElement === input) renderResults(LABELS.searchError);
                    return;
                }
                if (my !== reqId) return; // réponse périmée
                setBusy(false);
                let items = (list || []).map((raw) => toItem(raw, c));
                if (multiple && unique) items = items.filter((it) => !selected.some((s) => looseEq(s.value, it.value)));
                results = items.slice(0, opt(c, 'limit') ?? 8);
                if (document.activeElement !== input) return;
                if (!results.length) { if (opt(c, 'showEmpty') === false) showPanel(false); else renderResults(LABELS.noResults); return; }
                renderResults(null);
                if (selectable && opt(c, 'autoActivate') !== false) { active = results.findIndex((r) => !r.disabled); paintActive(); }
            };
            const schedule = () => {
                clearTimeout(timer);
                const c = resolve();
                const src = opt(c, 'source');
                const d = opt(c, 'debounce') ?? (typeof src === 'function' ? 250 : 0);
                if (d > 0) timer = setTimeout(doSearch, d); else doSearch();
            };

            input.addEventListener('input', () => {
                reqId++;
                if (own && !multiple && selected.length && input.value !== selected[0].label) { selected = []; changed(); }
                else if (!own) changed(); // le texte saisi EST la valeur
                schedule();
            });
            input.addEventListener('keydown', (e) => {
                if (e.isComposing) return;
                const isOpen = !panel.classList.contains('hidden');
                if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                    if (!isOpen) { if (e.key === 'ArrowDown') { e.preventDefault(); doSearch(); } return; }
                    if (!rows.length) return;
                    e.preventDefault();
                    const n = rows.length;
                    active = e.key === 'ArrowDown' ? (active >= n - 1 ? 0 : active + 1) : (active <= 0 ? n - 1 : active - 1);
                    paintActive();
                } else if (e.key === 'Enter' && isOpen && selectable && active >= 0 && results[active]) {
                    e.preventDefault();
                    e.stopPropagation(); // ne soumet pas le formulaire
                    choose(results[active]);
                } else if (e.key === 'Escape' && isOpen) {
                    e.preventDefault();
                    e.stopPropagation(); // ne ferme pas le dialogue
                    showPanel(false);
                }
            });
            input.addEventListener('focus', () => {
                mark(true);
                if (setCtx(resolve())) {                 // la cible a changé : les anciens résultats ne valent plus
                    reqId++; results = []; showPanel(false);
                    if (input.value.trim()) schedule();   // relance avec la source de la nouvelle cible
                } else if (results.length && input.value.trim().length >= (opt(cur, 'minChars') ?? 1)) renderResults(null);
            });
            input.addEventListener('blur', () => { clearTimeout(timer); showPanel(false); mark(false); });

            const setValue = (v) => {
                if (multiple) { selected = (Array.isArray(v) ? v : isFilled(v) ? [v] : []).map(fromValue); renderChips(); }
                else if (own) { selected = isFilled(v) ? [fromValue(v)] : []; input.value = selected.length ? selected[0].label : ''; }
                else input.value = v ?? '';
            };
            setValue(field.value);

            return {
                el: root,
                input,
                getValue: () => {
                    if (multiple) return selected.map(outValue);
                    if (own) return selected.length ? outValue(selected[0]) : '';
                    return input.value;
                },
                setValue,
                onError: (e) => swapBorder(input, e, OK, ERR),
            };
        },
    };
    /* ==========================================================
     * Construction d'un champ
     * ========================================================== */
    function passwordScore(v) {
        if (!v) return 0;
        let s = 0;
        if (v.length >= 8) s++;
        if (/[a-z]/.test(v) && /[A-Z]/.test(v)) s++;
        if (/\d/.test(v)) s++;
        if (/[^A-Za-z0-9]/.test(v)) s++;
        if (v.length < 6) s = Math.min(s, 1);
        return s;
    }

    /** (Re)remplit un <select>, options statiques ou calculées à partir des valeurs du formulaire. */
    function fillSelect(input, field, values) {
        const list = typeof field.options === 'function' ? field.options(values || {}) : field.options;
        input.textContent = '';
        if (field.placeholder) {
            const ph = document.createElement('option');
            ph.value = '';
            ph.textContent = field.placeholder;
            input.appendChild(ph);
        }
        (list || []).map(normOpt).forEach((o) => {
            const opt = document.createElement('option');
            opt.value = o.value;
            opt.textContent = o.label;
            input.appendChild(opt);
        });
    }

    function buildNativeInput(field, type, id, seed) {
        let input;
        if (type === 'textarea') {
            input = h('textarea', `${INPUT} ${OK} min-h-[4.5rem] resize-y`);
            if (field.rows) input.rows = field.rows;
        } else if (type === 'select') {
            input = h('select', `${INPUT} ${OK}`);
            fillSelect(input, field, seed);
        } else {
            input = h('input', `${INPUT} ${OK}${type === 'password' ? ' pr-10' : ''}`);
            input.type = type;
        }
        input.id = id;
        if (field.placeholder && type !== 'select') input.placeholder = field.placeholder;
        if (field.value !== undefined && field.value !== null) input.value = field.value;
        if (field.min !== undefined) input.min = field.min;
        if (field.max !== undefined) input.max = field.max;
        if (field.maxLength) input.maxLength = field.maxLength;
        if (field.autocomplete) input.setAttribute('autocomplete', field.autocomplete);
        return input;
    }

    function labelFor(field, id) {
        const label = h('label', 'mb-1 block text-xs font-semibold text-gray-700 dark:text-gray-200', { for: id, text: field.label });
        const star = h('span', 'ml-0.5 hidden text-red-500 dark:text-red-400', { text: '*', 'aria-hidden': 'true' });
        label.appendChild(star);
        label.__star = star;
        return label;
    }

    /**
     * Texte d'aide commun à tous les types de champs.
     * `extra` : classes d'espacement / alignement (par défaut 'mt-1').
     */
    function hintEl(field, id, extra = 'mt-1') {
        if (!field.hint) return null;
        return h('p', `text-xs text-gray-500 dark:text-gray-400 ${extra}`.trim(), { id: `${id}-hint`, text: field.hint });
    }

    // Les classes d'état utilisées pour la bordure des champs natifs (OK / ERR)
    const INPUT_OK_ONLY = OK;
    const ERR_ONLY = ERR;

    function buildField(field, idx, base, onFieldChange, ctx) {
        const type = field.type || 'text';
        const name = field.name !== undefined ? field.name : `field${idx}`;
        const id = `${base}-f${idx}`;
        const wrapper = h('div', 'min-w-0 text-left');
        const errorEl = h('p', 'mt-1 hidden text-xs text-red-600 dark:text-red-400', { id: `${id}-err`, role: 'alert' });
        const hintId = field.hint ? `${id}-hint` : null;
        const def = TYPES[type];
        const custom = !!def;
        const state = { hidden: false, required: false, disabled: false };
        let star = null;
        let input;
        let ref;
        let onErrorFn = null;
        let getValue;
        let setter = null;
        let selectRefresh = null;
        if (custom) {
            const made = def.render({
                id, field, values: ctx.seed, getForm: ctx.getForm,
                changed: () => { if (ref) { ref.setError(null); ref.notify(); } },
            });
            setter = made.setValue || null;
            input = made.input || made.el;
            getValue = made.getValue;
            onErrorFn = made.onError || null;
            if (field.label && !made.noLabel) {
                const lab = labelFor(field, id);
                star = lab.__star;
                wrapper.appendChild(lab);
            }
            wrapper.appendChild(made.el);
            const hint = hintEl(field, id, `mt-1 ${made.hintIndent || ''}`.trim());
            if (hint) wrapper.appendChild(hint);
        } else if (type === 'checkbox') {
            const label = h('label', 'flex cursor-pointer items-start gap-2.5 text-sm text-gray-700 dark:text-gray-200');
            input = h('input', 'mt-0.5 h-4 w-4 shrink-0 rounded border-gray-300 text-blue-600 focus:ring-2 focus:ring-blue-500 disabled:cursor-not-allowed dark:border-gray-600 dark:bg-gray-900 dark:focus:ring-blue-400', { type: 'checkbox', id });
            input.checked = !!field.value;
            label.append(input, h('span', '', { text: field.label || '' }));
            wrapper.appendChild(label);
            const hint = hintEl(field, id, 'mt-1 pl-[1.625rem]'); // aligné sous le texte du label (h-4 w-4 + gap-2.5)
            if (hint) wrapper.appendChild(hint);
            getValue = () => input.checked;
        } else {
            if (field.label) {
                const lab = labelFor(field, id);
                star = lab.__star;
                wrapper.appendChild(lab);
            }
            input = buildNativeInput(field, type, id, ctx.seed);
            const fmt = typeof field.format === 'function' ? field.format : FORMATS[field.format];
            let fmtFn = null;
            if (fmt && type !== 'number') {
                fmtFn = fmt;
                input.addEventListener('input', (e) => { if (!e.isComposing) reformat(input, fmt, field, false); });
                input.addEventListener('blur', () => reformat(input, fmt, field, true));
                if (field.format === 'tags' && field.enterSeparates !== false) {
                    input.addEventListener('keydown', (e) => {
                        if (e.key !== 'Enter' || e.isComposing) return;
                        e.preventDefault();
                        e.stopPropagation(); // pas de soumission du formulaire
                        if (input.value.trim() && !/[\s,;]$/.test(input.value)) {
                            input.value += ',';
                            reformat(input, fmt, field, false);
                            input.dispatchEvent(new Event('input', { bubbles: true }));
                        }
                    });
                }
            }
            const addons = (field.prefix || field.suffix) && type !== 'textarea' && type !== 'select' && type !== 'password';
            const holder = h('div', addons ? 'flex' : 'relative');
            if (addons) {
                addCls(input, 'min-w-0 flex-1');
                if (field.prefix) {
                    addCls(input, '!rounded-l-none');
                    holder.appendChild(h('span', `${ADDON} rounded-l-lg border-r-0`, { text: field.prefix }));
                }
                holder.appendChild(input);
                if (field.suffix) {
                    addCls(input, '!rounded-r-none');
                    holder.appendChild(h('span', `${ADDON} rounded-r-lg border-l-0`, { text: field.suffix }));
                }
            } else {
                holder.appendChild(input);
            }

            if (type === 'password') {
                const toggle = h('button', TOGGLE_CLS, { type: 'button', 'aria-label': LABELS.showPassword, 'aria-pressed': 'false' });
                toggle.innerHTML = icon('eye', 'h-5 w-5');
                toggle.addEventListener('mousedown', (e) => e.preventDefault());
                toggle.addEventListener('click', () => {
                    const show = input.type === 'password';
                    input.type = show ? 'text' : 'password';
                    toggle.innerHTML = icon(show ? 'eyeOff' : 'eye', 'h-5 w-5');
                    toggle.setAttribute('aria-label', show ? LABELS.hidePassword : LABELS.showPassword);
                    toggle.setAttribute('aria-pressed', String(show));
                });
                holder.appendChild(toggle);
            }
            wrapper.appendChild(holder);

            if (type === 'password' && field.strength) {
                const meter = h('div', 'mt-2');
                const bars = h('div', 'flex gap-1');
                const segs = [0, 1, 2, 3].map(() => bars.appendChild(h('div', `${SEG_BASE} ${SEG_EMPTY}`)));
                const lbl = h('p', 'mt-1 text-xs text-gray-500 dark:text-gray-400');
                meter.append(bars, lbl);
                wrapper.appendChild(meter);
                const refresh = () => {
                    const score = passwordScore(input.value);
                    const n = input.value ? Math.max(1, score) : 0;
                    segs.forEach((s, i) => { s.className = `${SEG_BASE} ${i < n ? STRENGTH_CLS[n - 1] : SEG_EMPTY}`; });
                    lbl.textContent = input.value ? LABELS.strength[score] : '';
                };
                input.addEventListener('input', refresh);
                refresh();
            }

            const showCounter = field.maxLength && (type === 'textarea' || field.counter);
            if (field.hint || showCounter) {
                const meta = h('div', 'mt-1 flex items-start justify-between gap-2');
                // le mt-1 est porté par `meta` : pas de marge propre sur le hint ici
                meta.appendChild(hintEl(field, id, 'flex-1') || h('p', 'flex-1'));
                if (showCounter) {
                    const counter = h('p', 'ml-auto shrink-0 text-xs text-gray-400 dark:text-gray-500');
                    const refresh = () => { counter.textContent = `${input.value.length}/${field.maxLength}`; };
                    input.addEventListener('input', refresh);
                    refresh();
                    meta.appendChild(counter);
                }
                wrapper.appendChild(meta);
            }
            const rawGet = () => (type === 'number' ? (input.value === '' ? '' : Number(input.value)) : input.value);
            getValue = fmtFn ? () => fmtFn(String(rawGet()), { field, final: true }) : rawGet;

            if (type === 'select' && typeof field.options === 'function') {
                selectRefresh = (values) => {
                    const cur = input.value;
                    fillSelect(input, field, values);
                    const keep = Array.from(input.options).some((o) => o.value === cur);
                    input.value = keep ? cur : (field.placeholder ? '' : (input.options[0] ? input.options[0].value : ''));
                    if (input.value !== cur) { ref.setError(null); ref.notify(); }
                };
            }
        }
        wrapper.appendChild(errorEl);

        ref = {
            field, name, type, def, input, wrapper, state,
            kind: custom ? 'custom' : 'native',
            touched: false,
            manuallyChanged: false,
            silent: false,
            getValue,
            refreshOptions: selectRefresh,
            setError(msg) {
                const on = !!msg;
                errorEl.textContent = on ? msg : '';
                errorEl.classList.toggle('hidden', !on);
                if (custom) { if (onErrorFn) onErrorFn(on); }
                else if (type !== 'checkbox') swapBorder(input, on, INPUT_OK_ONLY, ERR_ONLY);
                if (on) input.setAttribute('aria-invalid', 'true');
                else input.removeAttribute('aria-invalid');
                // le hint reste associé au champ ; l'erreur s'y ajoute quand elle est visible
                const desc = [hintId, on ? errorEl.id : null].filter(Boolean).join(' ');
                if (desc) input.setAttribute('aria-describedby', desc);
                else input.removeAttribute('aria-describedby');
            },
            focus() { if (input && typeof input.focus === 'function') input.focus(); },
            notify() {
                if (!ref.silent) {
                    ref.touched = true;
                    ref.manuallyChanged = true;
                }
                if (onFieldChange) onFieldChange(ref);
            },
            setValue(v) {
                if (custom) { if (setter) setter(v); }
                else if (type === 'checkbox') input.checked = !!v;
                else { input.value = v ?? ''; input.dispatchEvent(new Event(type === 'select' ? 'change' : 'input', { bubbles: true })); }
            },
            /** Modification programmatique (valeur dérivée) : ne compte pas comme une saisie manuelle. */
            silentSet(v) {
                ref.silent = true;
                try {
                    ref.setValue(v);
                    if (custom || type === 'checkbox') ref.notify();
                } finally { ref.silent = false; }
            },
            /** État dynamique : visibilité, obligation, désactivation. */
            applyState(s) {
                if (s.hidden !== state.hidden) {
                    state.hidden = s.hidden;
                    wrapper.classList.toggle('hidden', s.hidden);
                    if (s.hidden) ref.setError(null);
                }
                if (s.required !== state.required) {
                    state.required = s.required;
                    if (star) star.classList.toggle('hidden', !s.required);
                    if (input && type !== 'section') {
                        if (s.required) input.setAttribute('aria-required', 'true');
                        else input.removeAttribute('aria-required');
                    }
                }
                if (s.disabled !== state.disabled) {
                    state.disabled = s.disabled;
                    wrapper.querySelectorAll('input, select, textarea, button').forEach((el) => { el.disabled = s.disabled; });
                }
            },
        };
        if (hintId && input) input.setAttribute('aria-describedby', hintId);
        if (!custom) input.addEventListener(type === 'select' || type === 'checkbox' ? 'change' : 'input', () => { ref.setError(null); ref.notify(); });
        wrapper.addEventListener('focusout', (e) => {
            if (ctx.onBlur && !wrapper.contains(e.relatedTarget)) ctx.onBlur(ref);
        });
        return ref;
    }

    /* ==========================================================
     * Validation
     * ========================================================== */
    function validateField(ref, values) {
        const f = ref.field;
        const v = ref.getValue();
        const req = ref.state.required;

        if (ref.kind === 'custom') {
            const empty = ref.def.isEmpty ? ref.def.isEmpty(v) : (v === '' || v === null || v === undefined);
            if (empty) return req ? f.errorText || LABELS.required : undefined;
            if (ref.def.validate) {
                const r = ref.def.validate(v, values, f);
                if (r) return r;
            }
        } else if (ref.type === 'checkbox') {
            if (req && !v) return f.errorText || LABELS.required;
        } else {
            if (String(v).trim() === '') return req ? f.errorText || LABELS.required : undefined;
            const s = String(v);
            if (f.matches && !looseEq(values[f.matches], v)) return LABELS.match;
            if (ref.type === 'email' && !EMAIL_RE.test(s.trim())) return LABELS.email;
            if (f.minLength && s.length < f.minLength) return LABELS.minLength(f.minLength);
            if (f.maxLength && s.length > f.maxLength) return LABELS.maxLength(f.maxLength);
            if (f.pattern && !(f.pattern instanceof RegExp ? f.pattern : new RegExp(f.pattern)).test(s)) return f.patternText || LABELS.pattern;
            if (ref.type === 'number') {
                if (f.min !== undefined && v < Number(f.min)) return LABELS.min(f.min);
                if (f.max !== undefined && v > Number(f.max)) return LABELS.max(f.max);
            }
            if (f.matches && values[f.matches] !== v) return LABELS.match;
        }
        if (typeof f.validate === 'function') {
            const r = f.validate(v, values);
            if (typeof r === 'string' && r) return r;
            if (r === false) return LABELS.pattern;
        }
        return undefined;
    }

    /* ==========================================================
     * Normalisation d'un champ de configuration
     * ========================================================== */

    /** Aplatit les { type: 'row' | 'group', fields: [...] } : les enfants partagent la même ligne. */
    function flatten(list) {
        const out = [];
        list.forEach((f) => {
            if (f && (f.type === 'row' || f.type === 'group')) {
                const kids = (f.fields || []).filter(Boolean);
                const dflt = Math.max(1, Math.floor(12 / (kids.length || 1)));
                kids.forEach((k, i) => out.push(Object.assign({}, k, {
                    __rowSize: kids.length,
                    __rowSpan: dflt,
                    __parent: { hidden: f.hidden, showIf: f.showIf },
                    newLine: k.newLine !== undefined ? k.newLine : i === 0,
                })));
            } else out.push(f);
        });
        return out;
    }

    function normalizeField(f, index, values) {
        const raw = Object.assign({}, f);
        let type = raw.type || 'text';
        if (ALIASES[type]) type = ALIASES[type];
        if (type === 'search' && raw.source !== undefined) type = 'lookup';
        if (raw.name === undefined) raw.name = type === 'section' ? `__section${index}` : `field${index}`;
        if (values && values[raw.name] !== undefined && raw.value === undefined) raw.value = values[raw.name];

        if (type === 'phone') {
            return Object.assign({ autocomplete: 'tel', placeholder: '+212 6 12 34 56 78', pattern: PHONE_RE, patternText: LABELS.phone }, raw, { type: 'tel' });
        }
        if (NATIVE.has(type)) return Object.assign(raw, { type });

        const def = TYPES[type];
        if (!def) throw new Error(`Form : type de champ inconnu « ${type} ».`);
        return Object.assign({}, def.defaults, raw, { type });
    }

    /** Nombre de colonnes (sur 12) occupées par un champ. */
    function spanOf(field, cols) {
        let s = field.span !== undefined ? field.span : field.width;
        if (typeof s === 'string') s = WIDTHS[s];
        if (s === undefined || s === null) {
            if (field.fullWidth === true) s = 12;
            else if (field.__rowSpan) s = field.__rowSpan;
            else if (field.type === 'section' || field.type === 'textarea') s = 12;
            else s = Math.floor(12 / cols);
        }
        return Math.min(12, Math.max(1, Number(s) || 12));
    }

    /* ==========================================================
     * Moteur du dialogue
     * ========================================================== */
    const dialogStack = [];
    let scrollLocks = 0;

    function onGlobalKeydown(e) {
        const top = dialogStack[dialogStack.length - 1];
        if (top) top.onKeydown(e);
    }
    function lockScroll() { if (scrollLocks++ === 0) document.documentElement.classList.add('overflow-hidden'); }
    function unlockScroll() {
        if (--scrollLocks <= 0) { scrollLocks = 0; document.documentElement.classList.remove('overflow-hidden'); }
    }
    function trapFocus(e, root) {
        const items = [...root.querySelectorAll(FOCUSABLE)].filter((n) => n.offsetParent !== null);
        if (!items.length) { e.preventDefault(); return; }
        const first = items[0];
        const last = items[items.length - 1];
        const active = document.activeElement;
        if (e.shiftKey && (active === first || active === root)) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && active === last) { e.preventDefault(); first.focus(); }
    }

    const sameValue = (a, b) => {
        if (a === b) return true;
        if (Array.isArray(a) && Array.isArray(b)) return a.length === b.length && a.every((x, i) => x === b[i]);
        return false;
    };
    const eqLoose = (a, b) => (Array.isArray(a) || Array.isArray(b) ? sameValue(a, b) : String(a ?? '') === String(b ?? ''));

    function emptyFor(r) {
        if (r.type === 'checkbox' || r.type === 'switch') return false;
        if (r.type === 'tags') return [];
        if (r.type === 'rating') return 0;
        if (r.type === 'range') return r.field.min ?? 0;
        if (r.type === 'lookup') return r.field.multiple && !r.field.target ? [] : '';
        return '';
    }

    function openForm(cfg) {
        const variantName = cfg.variant || FORM_DEFAULTS.variant;
        const variant = VARIANTS[variantName];
        if (!variant) throw new Error(`Form : variante inconnue « ${variantName} ».`);
        const accent = ACCENTS[variant.accent] || ACCENTS.blue;

        const opts = Object.assign({}, FORM_DEFAULTS, variant, cfg);
        const title = opts.title !== null && opts.title !== undefined && opts.title !== '' ? opts.title : variant.title;
        const fieldsIn = Array.isArray(opts.fields) ? opts.fields : [];
        const normalized = flatten(fieldsIn).map((f, i) => normalizeField(f, i, opts.values));
        const seed = {};
        normalized.forEach((f) => { seed[f.name] = f.value; });

        return new Promise((resolve) => {
            const id = `form-dlg-${++uid}`;

            /* --- structure --- */
            const overlay = h('div', `${OVERLAY_BASE} ${OVERLAY_HIDDEN}`);
            const dialog = h('div', `${DIALOG_BASE} ${accent.top} ${HIDDEN}`, {
                role: 'dialog', 'aria-modal': 'true', tabindex: '-1',
                'aria-labelledby': title ? `${id}-title` : null,
                'aria-describedby': opts.message ? `${id}-msg` : null,
            });

            const head = h('div', 'flex items-start gap-3');
            head.innerHTML = `<span class="mt-0.5 shrink-0 ${accent.icon}">${icon(variant.icon, 'h-6 w-6')}</span><div class="min-w-0 flex-1"></div>`;
            const headText = head.lastElementChild;
            if (title) headText.appendChild(h('p', 'text-base font-semibold text-gray-900 dark:text-white', { id: `${id}-title`, text: title }));
            if (opts.message) headText.appendChild(h('p', 'mt-1 whitespace-pre-line break-words text-sm leading-relaxed text-gray-600 dark:text-gray-300', { id: `${id}-msg`, text: opts.message }));
            dialog.appendChild(head);

            const banner = h('div', 'mt-3 hidden rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300', { role: 'alert' });
            dialog.appendChild(banner);

            const body = h('div', 'mt-4 grid grid-cols-12 gap-x-4 gap-y-3.5');
            const actions = h('div', 'mt-5 flex justify-end gap-2');

            const cancelBtn = h('button', BTN_CANCEL, { type: 'button' });
            const confirmBtn = h('button', `${BTN_CONFIRM} ${accent.btn}`, { type: 'button' });
            const spin = h('span', 'hidden');
            spin.innerHTML = spinner('h-4 w-4');
            const confirmLabel = h('span');
            confirmBtn.append(spin, confirmLabel);

            const hasFields = normalized.length > 0;
            confirmLabel.textContent = opts.confirmText ?? (hasFields ? LABELS.save : LABELS.confirm);
            cancelBtn.textContent = opts.cancelText ?? LABELS.cancel;
            if (opts.showCancel) actions.appendChild(cancelBtn);
            actions.appendChild(confirmBtn);

            /* --- champs --- */
            const refs = normalized.map((f, i) => buildField(f, i, id, (r) => handleChange(r), {
                seed,
                getForm: () => formApi,
                onBlur: (r) => {
                    if (opts.validateOn !== 'blur' || !r.touched || r.state.hidden || r.state.disabled || opts.readonly) return;
                    r.setError(validateField(r, collect()) || null);
                },
            }));
            const real = refs.filter((r) => r.type !== 'section');

            if (refs.length) {
                dialog.appendChild(body);
                const customLayout = refs.some((r) => r.field.span !== undefined || r.field.width !== undefined || r.field.__rowSize);
                const cols = opts.columns === 'auto'
                    ? (customLayout ? 1 : (real.length > COLS_THRESHOLD ? 2 : 1))
                    : Math.min(3, Math.max(1, Number(opts.columns) || 1));
                const needCols = Math.min(3, Math.max(cols, ...refs.map((r) => Math.ceil(12 / spanOf(r.field, cols)))));
                swapCls(dialog, 'max-w-md', opts.size && SIZES[opts.size] ? SIZES[opts.size] : COLS_WIDTH[needCols]);
                refs.forEach((r) => {
                    const span = spanOf(r.field, cols);
                    addCls(r.wrapper, `col-span-12 ${SPAN[span]}`);
                    if (r.field.newLine) addCls(r.wrapper, 'sm:col-start-1');
                    body.appendChild(r.wrapper);
                });
            }
            dialog.appendChild(actions);

            /* --- valeurs --- */
            const collect = () => {
                const values = {};
                refs.forEach((r) => { values[r.name] = r.getValue(); });
                return values;
            };
            let initial = {};
            const diff = (values, list) => {
                const out = {};
                list.forEach((r) => { if (!sameValue(values[r.name], initial[r.name])) out[r.name] = values[r.name]; });
                return out;
            };
            const shape = (values, list) => {
                if (!list.length) return true;
                if (list.length === 1) return values[list[0].name];
                const out = {};
                list.forEach((r) => { out[r.name] = values[r.name]; });
                return out;
            };

            /* --- état --- */
            let done = false;
            let loading = false;

            const finish = (result) => {
                if (done) return;
                done = true;
                modal.destroy();
                resolve(result);
            };
            const cancel = () => { if (!loading) finish(null); };

            const setLoading = (on) => {
                loading = on;
                confirmBtn.disabled = on;
                cancelBtn.disabled = on;
                spin.classList.toggle('hidden', !on);
            };
            const setError = (msg) => {
                banner.textContent = msg || '';
                banner.classList.toggle('hidden', !msg);
            };
            const clearErrors = () => { setError(null); refs.forEach((r) => r.setError(null)); };
            const setErrors = (errors) => {
                if (!errors) return;
                let first = null;
                refs.forEach((r) => {
                    let msg = errors[r.name];
                    if (Array.isArray(msg)) msg = msg[0];
                    if (msg) {
                        r.setError(String(msg));
                        if (!first) first = r;
                    }
                });
                if (first) first.focus();
            };
            const handleError = (err) => {
                if (err && typeof err.errors === 'object' && err.errors) setErrors(err.errors);
                else setError((err && err.message) || LABELS.genericError);
            };
            const byName = (n) => real.find((r) => r.name === n);

            /* --- dépendances entre champs --- */
            let depth = 0;

            const runDerived = (changedName) => {
                if (depth > 8) return;
                depth++;
                try {
                    refs.forEach((r) => {
                        const f = r.field;
                        const fn = f.computed || f.derive;
                        if (!f.from && !fn) return;
                        if (changedName != null && r.name === changedName) return;
                        const deps = f.from ? [].concat(f.from) : (f.dependsOn ? [].concat(f.dependsOn) : null);
                        if (changedName != null && deps && !deps.includes(changedName)) return;
                        if (!f.computed && r.touched && !(
                            f.regenerateOnSourceChange
                            && changedName !== null
                            && deps?.includes(changedName)
                            && !r.manuallyChanged
                        )) return;
                        const values = collect();
                        let val;
                        if (f.from) {
                            const src = [].concat(f.from).map((n) => (values[n] === undefined || values[n] === null ? '' : String(values[n]))).join(' ').trim();
                            val = typeof f.transform === 'function'
                                ? f.transform(src, values)
                                : (r.type === 'slug' ? slugify(src, { separator: f.separator || '-' }) : src);
                        } else {
                            val = fn(values, formApi);
                        }
                        if (val === undefined || val === null) return;
                        if (eqLoose(val, r.getValue())) return;
                        r.silentSet(val);
                    });
                } finally { depth--; }
            };

            const refreshOptions = (changedName) => {
                const selects = refs.filter((r) => r.refreshOptions);
                if (!selects.length) return;
                const values = collect();
                selects.forEach((r) => {
                    if (changedName != null && r.name === changedName) return;
                    const deps = r.field.dependsOn;
                    if (changedName != null && deps && ![].concat(deps).includes(changedName)) return;
                    r.refreshOptions(values);
                });
            };

            const refreshStates = () => {
                const values = collect();
                refs.forEach((r) => {
                    const f = r.field;
                    const p = f.__parent;
                    const hidden = !!(
                        (p && ((p.hidden !== undefined && evalCond(p.hidden, values, formApi)) || (p.showIf !== undefined && !evalCond(p.showIf, values, formApi))))
                        || (f.hidden !== undefined && evalCond(f.hidden, values, formApi))
                        || (f.showIf !== undefined && !evalCond(f.showIf, values, formApi))
                    );
                    const reqSrc = f.requiredIf !== undefined ? f.requiredIf : f.required;
                    const required = r.type !== 'section' && reqSrc !== undefined && evalCond(reqSrc, values, formApi);
                    const disSrc = f.disabledIf !== undefined ? f.disabledIf : f.disabled;
                    const disabled = !!opts.readonly || !!f.computed || (disSrc !== undefined && evalCond(disSrc, values, formApi));
                    const wasHidden = r.state.hidden;
                    r.applyState({ hidden, required, disabled });
                    if (hidden && !wasHidden && f.clearOnHide && r.type !== 'section') r.silentSet(emptyFor(r));
                });
            };

            function handleChange(r) {
                runDerived(r.name);
                refreshOptions(r.name);
                refreshStates();
                const v = r.getValue();
                if (typeof r.field.onInput === 'function') r.field.onInput(v, formApi);
                if (typeof opts.onChange === 'function') opts.onChange(r.name, v, formApi);
            }
            /* --- historique du focus (le plus récent en premier) --- */
            const focusLog = [];
            dialog.addEventListener('focusin', (e) => {
                const r = real.find((x) => x.wrapper.contains(e.target));
                if (!r) return;
                const i = focusLog.indexOf(r.name);
                if (i > -1) focusLog.splice(i, 1);
                focusLog.unshift(r.name);
                if (focusLog.length > 20) focusLog.pop();
            });
            const formApi = {
                get: (n) => (n === undefined ? collect() : (byName(n) ? byName(n).getValue() : undefined)),
                set(n, v) { const r = byName(n); if (r) { r.setValue(v); r.setError(null); } return formApi; },
                setAll(obj) { Object.entries(obj || {}).forEach(([n, v]) => formApi.set(n, v)); return formApi; },
                focus(n) { const r = byName(n); if (r) r.focus(); return formApi; },
                /** Dernier champ ayant eu le focus, hors `exclude`, limité à `among` si fourni (ignore masqués/désactivés). */
                lastFocused(exclude, among) {
                    const ex = [].concat(exclude ?? []);
                    const list = among ? [].concat(among) : null;
                    return focusLog.find((n) => {
                        if (ex.includes(n) || (list && !list.includes(n))) return false;
                        const r = byName(n);
                        return !!r && !r.state.hidden && !r.state.disabled;
                    });
                },

                field: (n) => (byName(n) ? byName(n).field : null),
                el: (n) => (byName(n) ? byName(n).wrapper : null),
                disable(n, on = true) {
                    const r = byName(n);
                    if (r) r.wrapper.querySelectorAll('input, select, textarea, button').forEach((el) => { el.disabled = on; });
                    return formApi;
                },
                /** Re-synchronise un champ dérivé (slug, from, derive) avec sa source. */
                regenerate(n) {
                    const r = byName(n);
                    if (r) {
                        r.touched = false;
                        r.manuallyChanged = false;
                        runDerived(null);
                    }
                    return formApi;
                },
                refresh() { runDerived(null); refreshOptions(null); refreshStates(); return formApi; },
                setErrors, clearErrors, setError, setLoading,
                close: (v) => finish(v === undefined ? null : v),
            };

            /* --- initialisation des dépendances --- */
            refs.forEach((r) => { if (r.field.from || r.field.derive) r.touched = isFilled(r.field.value); });
            refreshOptions(null);
            runDerived(null);
            refreshStates();
            initial = collect();

            const trySubmit = () => {
                if (loading) return;
                clearErrors();
                const values = collect();
                const active = real.filter((r) => !r.state.hidden && !r.state.disabled);

                if (!opts.readonly) {
                    let first = null;
                    refs.forEach((r) => {
                        if (r.state.hidden || r.state.disabled) return;
                        const msg = validateField(r, values);
                        if (msg) { r.setError(msg); if (!first) first = r; }
                    });
                    if (first) { first.focus(); return; }
                }

                const visible = {};
                active.forEach((r) => { visible[r.name] = values[r.name]; });
                const changes = diff(visible, active);
                const isDirty = Object.keys(changes).length > 0;

                if (opts.requireChange && active.length && !isDirty && typeof opts.onSubmit === 'function') {
                    setError(LABELS.noChange);
                    return;
                }

                const result = shape(visible, active);

                if (typeof opts.onSubmit === 'function') {
                    const api = Object.assign({}, formApi, {
                        close: (value) => finish(value === undefined ? result : value),
                        changes, isDirty, values: Object.assign({}, visible),
                    });
                    let ret;
                    try {
                        ret = opts.onSubmit(result, api);
                    } catch (e) {
                        handleError(e);
                        return;
                    }
                    if (ret && typeof ret.then === 'function') {
                        setLoading(true);
                        ret.then(undefined, handleError).then(() => { if (!done) setLoading(false); });
                    }
                    return;
                }
                finish(result);
            };

            /* --- affichage --- */
            const previous = document.activeElement;
            const autoZ = opts.zIndex === null || opts.zIndex === undefined;
            overlay.style.zIndex = autoZ ? (CONFIG.zIndex + (zCounter += 10)) : opts.zIndex;
            overlay.appendChild(dialog);
            document.body.appendChild(overlay);
            lockScroll();
            void overlay.offsetWidth;
            requestAnimationFrame(() => {
                swapCls(overlay, OVERLAY_HIDDEN, OVERLAY_SHOWN);
                swapCls(dialog, HIDDEN, SHOWN);
            });

            const entry = {
                onKeydown(e) {
                    if (e.key === 'Escape') {
                        if (opts.closeOnEsc) { e.preventDefault(); cancel(); }
                    } else if (e.key === 'Tab') {
                        trapFocus(e, dialog);
                    } else if (e.key === 'Enter' && !e.isComposing) {
                        if (e.ctrlKey || e.metaKey) { e.preventDefault(); trySubmit(); return; }
                        const tag = e.target.tagName;
                        if (tag === 'TEXTAREA' || tag === 'BUTTON') return;
                        e.preventDefault();
                        trySubmit();
                    }
                },
            };
            dialogStack.push(entry);
            if (dialogStack.length === 1) document.addEventListener('keydown', onGlobalKeydown);

            if (opts.closeOnOverlayClick) {
                overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) cancel(); });
            }

            const modal = {
                destroy() {
                    const i = dialogStack.indexOf(entry);
                    if (i > -1) dialogStack.splice(i, 1);
                    if (!dialogStack.length) document.removeEventListener('keydown', onGlobalKeydown);
                    unlockScroll();
                    if (autoZ) zCounter -= 10;
                    swapCls(overlay, OVERLAY_SHOWN, OVERLAY_HIDDEN);
                    swapCls(dialog, SHOWN, HIDDEN);
                    setTimeout(() => overlay.remove(), 200);
                    if (previous && typeof previous.focus === 'function' && document.contains(previous)) previous.focus();
                },
            };

            cancelBtn.addEventListener('click', cancel);
            confirmBtn.addEventListener('click', trySubmit);

            const focusable = real.filter((r) => !r.state.hidden && !r.state.disabled && r.input);
            let focusEl;
            if (opts.autoFocus === 'cancel' && opts.showCancel) focusEl = cancelBtn;
            else if (!opts.readonly && focusable.length) focusEl = (focusable.find((r) => r.field.autofocus) || focusable[0]).input;
            else focusEl = confirmBtn;
            focusEl.focus();
            if (typeof opts.onOpen === 'function') opts.onOpen(formApi);
        });
    }

    /* ==========================================================
     * Instance fluide
     * ========================================================== */
    class FormInstance {
        constructor(config = {}) {
            this.config = Object.assign({}, config);
            this.fields = Array.isArray(config.fields) ? [...config.fields] : [];
        }
        /** Modifie des options du formulaire (title, values, onSubmit, variant...). */
        set(options = {}) { Object.assign(this.config, options); return this; }
        add(type, name, options = {}) {
            this.fields.push(Object.assign({}, options, { type, name }));
            return this;
        }
        section(title, options = {}) {
            this.fields.push(Object.assign({}, options, { type: 'section', title }));
            return this;
        }
        /** Plusieurs champs sur la même ligne : .row([{ type, name, ... }, ...], { showIf }) */
        row(fields, options = {}) {
            this.fields.push(Object.assign({}, options, { type: 'row', fields }));
            return this;
        }
        /** Ouvre le formulaire. */
        open(overrides = {}) {
            return openForm(Object.assign({}, this.config, overrides, { fields: this.fields }));
        }
    }

    const NATIVE_FLUENT = ['text', 'email', 'password', 'number', 'tel', 'url', 'date', 'time', 'datetime', 'month', 'week', 'search', 'textarea', 'select', 'checkbox', 'phone'];
    const defineFluent = (t) => { FormInstance.prototype[t] = function (name, options) { return this.add(t, name, options); }; };
    NATIVE_FLUENT.concat(Object.keys(TYPES).filter((t) => t !== 'section')).forEach(defineFluent);

    /* ==========================================================
     * API publique
     * ========================================================== */
    const Form = {
        version: '2.1.0',
        instance: (config) => new FormInstance(config),
        open: (config = {}) => openForm(config),
        slugify,
        configure(config = {}) {
            const { labels, ...rest } = config;
            if (labels) Object.assign(LABELS, labels);
            Object.assign(CONFIG, rest);
            return Form;
        },
        /** Déclare ou remplace une variante : { accent, icon, title, confirmText, ... } */
        variant(name, def) {
            VARIANTS[name] = Object.assign({}, def);
            if (!(name in Form) || Form[name].__variant) {
                const fn = (config) => openForm(Object.assign({}, config, { variant: name }));
                fn.__variant = true;
                Form[name] = fn;
            }
            return Form;
        },
        /** Déclare un type de champ personnalisé. */
        register(type, def) {
            if (!def || typeof def.render !== 'function') throw new Error('Form.register : "render" est requis.');
            TYPES[type] = def;
            defineFluent(type);
            return Form;
        },
        types: () => [...NATIVE, 'datetime', 'phone', 'row', ...Object.keys(TYPES)],
        variants: () => Object.keys(VARIANTS),
    };

    Object.keys(VARIANTS).forEach((name) => Form.variant(name, VARIANTS[name]));
    // Helpers exposés pour les types enregistrés hors module
    Form._ = {
        h, INPUT, OK, ERR, BOX_OK, BOX_ERR, FOCUS_RING,
        addCls, rmCls, swapCls, swapBorder, spinner, icon, toks,
        normalizeField, // si besoin
    };
    global.Form = Form;
    if (typeof module !== 'undefined' && module.exports) module.exports = Form;
})(typeof window !== 'undefined' ? window : globalThis);
