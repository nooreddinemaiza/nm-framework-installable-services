document.addEventListener('DOMContentLoaded', () => {
    const dialog = document.querySelector('[data-articles-settings-dialog]');
    const form = dialog?.querySelector('[data-articles-settings-form]');
    if (!dialog || !form) return;

    dialog.querySelectorAll('[data-articles-settings-close]').forEach((button) => {
        button.addEventListener('click', () => dialog.close());
    });
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    });
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const submit = form.querySelector('button[type="submit"]');
        if (submit) submit.disabled = true;
        try {
            const payload = Object.fromEntries(new FormData(form).entries());
            delete payload.csrf_token;
            const response = await window.api.post(form.action, payload, { toast: false });
            if (!response.success) throw new Error(response.message || 'Impossible d’enregistrer la configuration.');
            window.Toaster.success(response.message || 'Configuration enregistrée.');
            dialog.close();
        } catch (error) {
            window.Toaster.warning(error.message || 'Impossible d’enregistrer la configuration.');
        } finally {
            if (submit) submit.disabled = false;
        }
    });
});
