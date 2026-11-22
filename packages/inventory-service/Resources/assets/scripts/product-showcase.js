document.addEventListener('DOMContentLoaded', () => {
    const slides = Array.from(document.querySelectorAll('[data-product-slide]'));
    const thumbnails = Array.from(document.querySelectorAll('[data-product-thumb]'));

    const setActiveSlide = (activeIndex) => {
        slides.forEach((slide, index) => {
            const active = index === activeIndex;
            slide.hidden = !active;
            slide.setAttribute('aria-hidden', active ? 'false' : 'true');
            if (!active) slide.querySelectorAll('video').forEach((video) => video.pause());
        });

        thumbnails.forEach((thumbnail, index) => {
            const active = index === activeIndex;
            thumbnail.setAttribute('aria-pressed', active ? 'true' : 'false');
            thumbnail.classList.toggle('border-teal-600', active);
            thumbnail.classList.toggle('ring-2', active);
            thumbnail.classList.toggle('ring-teal-600/15', active);
            thumbnail.classList.toggle('dark:border-teal-400', active);
            thumbnail.classList.toggle('border-transparent', !active);
        });
    };

    thumbnails.forEach((thumbnail) => {
        thumbnail.addEventListener('click', () => {
            setActiveSlide(Number.parseInt(thumbnail.dataset.productThumb, 10));
        });
    });

    const feedback = document.querySelector('[data-share-feedback]');
    let feedbackTimeout;
    const showFeedback = (message) => {
        if (!feedback) return;
        feedback.textContent = message;
        feedback.setAttribute('aria-hidden', 'false');
        feedback.classList.remove('opacity-0');
        feedback.classList.add('opacity-100');
        window.clearTimeout(feedbackTimeout);
        feedbackTimeout = window.setTimeout(() => {
            feedback.classList.remove('opacity-100');
            feedback.classList.add('opacity-0');
            feedback.setAttribute('aria-hidden', 'true');
        }, 2400);
    };

    const copyText = async (value) => {
        if (navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(value);
            return;
        }

        const input = document.createElement('textarea');
        input.value = value;
        input.setAttribute('readonly', '');
        input.style.position = 'fixed';
        input.style.opacity = '0';
        document.body.append(input);
        input.select();
        const copied = document.execCommand('copy');
        input.remove();
        if (!copied) throw new Error('La copie automatique n’est pas disponible dans ce navigateur.');
    };

    document.querySelectorAll('[data-product-share]').forEach((button) => {
        button.addEventListener('click', async () => {
            const url = new URL(button.dataset.shareUrl || window.location.href, window.location.origin).href;
            const title = button.dataset.shareTitle || document.title;

            try {
                if (button.dataset.shareMode !== 'copy' && navigator.share) {
                    await navigator.share({ title, url });
                    return;
                }

                await copyText(url);
                showFeedback('Lien de la fiche copié.');
            } catch (error) {
                if (error?.name === 'AbortError') return;
                showFeedback(error?.message || 'Impossible de partager cette fiche.');
            }
        });
    });
});
