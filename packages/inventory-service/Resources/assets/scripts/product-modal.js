document.addEventListener('click', (event) => {
    const openButton = event.target.closest('[data-product-modal-open]');
    if (openButton) {
        const card = openButton.closest('[data-product-card]');
        const dialog = card?.querySelector('[data-product-modal]');
        if (dialog instanceof HTMLDialogElement && !dialog.open) {
            dialog.showModal();
        }
        return;
    }

    const closeButton = event.target.closest('[data-product-modal-close]');
    if (closeButton) {
        const dialog = closeButton.closest('[data-product-modal]');
        if (dialog instanceof HTMLDialogElement && dialog.open) {
            dialog.close();
        }
        return;
    }

    const dialog = event.target.closest('dialog[data-product-modal]');
    if (dialog && event.target === dialog) {
        dialog.close();
    }
});
