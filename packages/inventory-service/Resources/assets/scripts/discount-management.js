(() => {
    const root = document;
    const localDateTimeValue = (utcValue) => {
        const date = new Date(`${String(utcValue).replace(' ', 'T')}Z`);
        if (Number.isNaN(date.getTime())) return '';
        const local = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
        return local.toISOString().slice(0, 19);
    };

    const utcDateTimeValue = (localValue) => {
        const date = new Date(localValue);
        if (Number.isNaN(date.getTime())) throw new Error('Choisissez une période valide.');
        return date.toISOString().replace(/\.\d{3}Z$/, 'Z');
    };

    const dateDefaults = () => {
        const now = new Date();
        const start = new Date(now.getTime() - now.getTimezoneOffset() * 60000);
        const end = new Date(start.getTime() + 7 * 24 * 60 * 60 * 1000);
        return {
            starts: start.toISOString().slice(0, 19),
            ends: end.toISOString().slice(0, 19)
        };
    };

    const notifyError = (error) => window.Toaster?.warning(error?.message || 'Impossible de gérer cette réduction.');

    const loadDiscounts = async (dialog) => {
        const {targetType, targetId} = dialog.dataset;
        const url = `/dashboard/inventory/discounts/target?target_type=${encodeURIComponent(targetType)}&target_id=${encodeURIComponent(targetId)}`;
        const response = await window.api.get(url, {toast: false});
        if (!response.success || !Array.isArray(response.data)) {
            throw new Error(response.message || 'Impossible de charger les réductions de cette cible.');
        }

        const list = dialog.querySelector('[data-discount-list]');
        list.replaceChildren();
        if (response.data.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'text-sm text-slate-500 dark:text-slate-400';
            empty.textContent = 'Aucune réduction configurée pour cette cible.';
            list.append(empty);
            return;
        }

        response.data.forEach((discount) => {
            const row = document.createElement('article');
            row.className = 'flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 p-3 dark:border-slate-700';
            const value = discount.discount_type === 'percentage'
                ? `${discount.value} %`
                : `${discount.value} ${discount.currency}`;
            const details = document.createElement('div');
            details.className = 'min-w-0';
            const summary = document.createElement('p');
            summary.className = 'font-semibold text-slate-900 dark:text-white';
            summary.textContent = `${value} · ${discount.discount_type === 'percentage' ? 'Pourcentage' : 'Montant fixe'}`;
            const period = document.createElement('p');
            period.className = 'mt-1 text-xs text-slate-500 dark:text-slate-400';
            period.textContent = `${discount.starts_at} → ${discount.ends_at} UTC · ${discount.is_active ? 'Activée' : 'Désactivée'}`;
            details.append(summary, period);

            const actions = document.createElement('div');
            actions.className = 'flex gap-2';
            const edit = document.createElement('button');
            edit.type = 'button';
            edit.className = 'rounded-lg px-3 py-1.5 text-xs font-semibold text-teal-800 ring-1 ring-slate-300 dark:text-teal-300 dark:ring-slate-700';
            edit.textContent = 'Modifier';
            edit.addEventListener('click', () => showDiscountForm(dialog, discount));
            const status = document.createElement('button');
            status.type = 'button';
            status.className = 'rounded-lg px-3 py-1.5 text-xs font-semibold text-slate-700 ring-1 ring-slate-300 dark:text-slate-200 dark:ring-slate-700';
            status.textContent = discount.is_active ? 'Désactiver' : 'Activer';
            status.addEventListener('click', async () => {
                status.disabled = true;
                try {
                    const result = await window.api.post('/dashboard/inventory/discounts/status', {
                        discount_id: discount.id,
                        action: discount.is_active ? 'deactivate' : 'activate'
                    }, {toast: false});
                    if (!result.success) throw new Error(result.message || 'Impossible de modifier le statut.');
                    window.Toaster?.success(result.message || 'Statut de la réduction mis à jour.');
                    document.dispatchEvent(new CustomEvent('inventory:discount-mutation'));
                    await loadDiscounts(dialog);
                } catch (error) {
                    notifyError(error);
                } finally {
                    status.disabled = false;
                }
            });
            actions.append(edit, status);
            row.append(details, actions);
            list.append(row);
        });
    };

    const showDiscountForm = (dialog, discount = null) => {
        const editor = dialog.querySelector('[data-discount-editor]');
        const form = editor.querySelector('form');
        const defaults = dateDefaults();
        form.elements.discount_id.value = discount?.id ?? '';
        form.elements.discount_type.value = discount?.discount_type ?? 'percentage';
        form.elements.value.value = discount?.value ?? '';
        form.elements.currency.value = discount?.currency ?? '';
        form.elements.starts_at.value = discount ? localDateTimeValue(discount.starts_at) : defaults.starts;
        form.elements.ends_at.value = discount ? localDateTimeValue(discount.ends_at) : defaults.ends;
        form.elements.is_active.checked = discount ? Boolean(discount.is_active) : true;
        editor.querySelector('[data-discount-editor-title]').textContent = discount ? 'Modifier la réduction' : 'Nouvelle réduction';
        editor.hidden = false;
        dialog.querySelector('[data-discount-type]').dispatchEvent(new Event('change'));
        editor.scrollIntoView({block: 'nearest'});
    };

    root.addEventListener('click', async (event) => {
        const opener = event.target.closest('[data-open-discount-modal]');
        if (!opener) return;
        const dialog = opener.closest('[data-discount-scope]')?.querySelector('[data-discount-modal]')
            || root.querySelector('[data-discount-modal]');
        if (!dialog) return;
        dialog.dataset.targetType = opener.dataset.discountTargetType;
        dialog.dataset.targetId = opener.dataset.discountTargetId;
        dialog.querySelector('[data-discount-target-name]').textContent = opener.dataset.discountTargetName || 'Cible';
        dialog.querySelector('[data-discount-editor]').hidden = true;
        dialog.showModal();
        try {
            await loadDiscounts(dialog);
        } catch (error) {
            notifyError(error);
            dialog.querySelector('[data-discount-list]').textContent = error.message || 'Impossible de charger les réductions.';
        }
    });

    root.addEventListener('click', (event) => {
        if (event.target.matches('[data-discount-new]')) showDiscountForm(event.target.closest('[data-discount-modal]'));
        if (event.target.matches('[data-discount-cancel]')) event.target.closest('[data-discount-editor]').hidden = true;
        if (event.target.matches('[data-discount-close]')) event.target.closest('dialog').close();
    });

    root.addEventListener('change', (event) => {
        if (!event.target.matches('[data-discount-type]')) return;
        const currency = event.target.form.elements.currency;
        const fixed = event.target.value === 'fixed';
        currency.required = fixed;
        currency.closest('[data-discount-currency]').hidden = !fixed;
    });

    root.addEventListener('submit', async (event) => {
        const form = event.target.closest('[data-discount-form]');
        if (!form) return;
        event.preventDefault();
        const dialog = form.closest('[data-discount-modal]');
        const values = new FormData(form);
        const submit = form.querySelector('[type=submit]');
        submit.disabled = true;
        try {
            const payload = {
                discount_id: values.get('discount_id') || undefined,
                target_type: dialog.dataset.targetType,
                target_id: dialog.dataset.targetId,
                discount_type: values.get('discount_type'),
                value: values.get('value'),
                currency: values.get('discount_type') === 'fixed' ? values.get('currency') : null,
                starts_at: utcDateTimeValue(values.get('starts_at')),
                ends_at: utcDateTimeValue(values.get('ends_at')),
                is_active: form.elements.is_active.checked
            };
            const response = await window.api.post('/dashboard/inventory/discounts/save', payload, {toast: false});
            if (!response.success) throw new Error(response.message || 'Impossible d’enregistrer la réduction.');
            window.Toaster?.success(response.message || 'Réduction enregistrée.');
            document.dispatchEvent(new CustomEvent('inventory:discount-mutation'));
            form.closest('[data-discount-editor]').hidden = true;
            await loadDiscounts(dialog);
        } catch (error) {
            notifyError(error);
        } finally {
            submit.disabled = false;
        }
    });
})();
