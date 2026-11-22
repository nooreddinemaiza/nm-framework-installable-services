document.addEventListener('DOMContentLoaded', () => {
    const dialog = document.querySelector('[data-inventory-settings-dialog]');
    const form = dialog?.querySelector('[data-inventory-settings-form]');
    if (!dialog || !form) return;

    document.querySelectorAll('[data-open-inventory-settings]').forEach((button) => {
        button.addEventListener('click', () => dialog.showModal());
    });

    dialog.querySelectorAll('[data-inventory-settings-close]').forEach((button) => {
        button.addEventListener('click', () => dialog.close());
    });

    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const submitButton = form.querySelector('button[type="submit"]');
        if (submitButton) submitButton.disabled = true;

        try {
            const payload = Object.fromEntries(new FormData(form).entries());
            delete payload.csrf_token;
            delete payload.return_to;
            const result = await window.api.post(form.action, payload, {toast: false});
            if (!result.success) {
                throw new Error(result.message || 'Impossible d’enregistrer la configuration.');
            }

            window.Toaster.success(result.message || 'Configuration enregistrée.');
            dialog.close();
            window.location.reload();
        } catch (error) {
            window.Toaster.warning(error.message || 'Impossible d’enregistrer la configuration.');
        } finally {
            if (submitButton) submitButton.disabled = false;
        }
    });
});
