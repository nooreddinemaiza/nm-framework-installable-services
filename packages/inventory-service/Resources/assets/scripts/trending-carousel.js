(() => {
    const carousels = document.querySelectorAll('[data-trending-carousel]');

    carousels.forEach((carousel) => {
        const slides = Array.from(carousel.querySelectorAll('[data-trending-slide]'));
        const controls = carousel.querySelectorAll('[data-carousel-controls]');
        const previous = carousel.querySelector('[data-carousel-previous]');
        const next = carousel.querySelector('[data-carousel-next]');
        const toggle = carousel.querySelector('[data-carousel-toggle]');
        const indicators = Array.from(carousel.querySelectorAll('[data-carousel-indicator]'));
        const announcement = carousel.querySelector('[data-carousel-announcement]');

        if (slides.length < 2) {
            return;
        }

        let current = Math.max(0, slides.findIndex((slide) => slide.dataset.active === 'true'));
        let timer = 0;
        let manuallyPaused = false;
        let touchStartX = null;
        let transitionSequence = 0;
        const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
        const interval = 7000;

        carousel.dataset.carouselReady = 'true';
        controls.forEach((control) => {
            control.hidden = false;
        });

        const stopTimer = () => {
            window.clearTimeout(timer);
            timer = 0;
        };

        const schedule = () => {
            stopTimer();
            if (document.hidden || manuallyPaused) {
                return;
            }

            timer = window.setTimeout(() => {
                showSlide((current + 1) % slides.length);
                schedule();
            }, interval);
        };

        const showSlide = (index, announce = false) => {
            const target = (index + slides.length) % slides.length;
            if (target === current) {
                return;
            }

            const previousSlide = slides[current];
            const nextSlide = slides[target];
            const sequence = ++transitionSequence;
            previousSlide.removeAttribute('data-active');
            previousSlide.dataset.leaving = 'true';
            previousSlide.setAttribute('aria-hidden', 'true');
            previousSlide.inert = true;

            nextSlide.hidden = false;
            nextSlide.inert = false;
            delete nextSlide.dataset.leaving;
            nextSlide.setAttribute('aria-hidden', 'false');
            nextSlide.removeAttribute('data-active');
            void nextSlide.offsetWidth;
            window.requestAnimationFrame(() => {
                if (sequence === transitionSequence) {
                    nextSlide.dataset.active = 'true';
                }
            });

            current = target;
            indicators.forEach((indicator, indicatorIndex) => {
                indicator.setAttribute('aria-pressed', indicatorIndex === current ? 'true' : 'false');
            });

            if (announce && announcement) {
                announcement.textContent = `Diapositive ${current + 1} sur ${slides.length} : ${slides[current].querySelector('h3')?.textContent.trim() || ''}`;
            }

            const finishTransition = () => {
                if (previousSlide.dataset.active !== 'true') {
                    previousSlide.hidden = true;
                    delete previousSlide.dataset.leaving;
                }
            };
            const transitionDuration = reduceMotion.matches ? 0 : 700;
            if (transitionDuration === 0) {
                finishTransition();
            } else {
                window.setTimeout(finishTransition, transitionDuration);
            }
        };

        previous?.addEventListener('click', () => {
            showSlide((current - 1 + slides.length) % slides.length, true);
            schedule();
        });
        next?.addEventListener('click', () => {
            showSlide((current + 1) % slides.length, true);
            schedule();
        });
        indicators.forEach((indicator) => {
            indicator.addEventListener('click', () => {
                showSlide(Number(indicator.dataset.carouselIndicator), true);
                schedule();
            });
        });

        toggle?.addEventListener('click', () => {
            manuallyPaused = !manuallyPaused;
            toggle.setAttribute('aria-pressed', manuallyPaused ? 'true' : 'false');
            toggle.setAttribute('aria-label', manuallyPaused ? 'Reprendre le défilement automatique' : 'Mettre en pause le carrousel');
            toggle.textContent = manuallyPaused ? 'Reprendre' : 'Pause';
            schedule();
        });

        carousel.addEventListener('keydown', (event) => {
            if (event.altKey || event.ctrlKey || event.metaKey) {
                return;
            }
            if (event.key === 'ArrowLeft') {
                event.preventDefault();
                showSlide((current - 1 + slides.length) % slides.length, true);
                schedule();
            } else if (event.key === 'ArrowRight') {
                event.preventDefault();
                showSlide((current + 1) % slides.length, true);
                schedule();
            }
        });
        carousel.addEventListener('pointerdown', (event) => {
            if (event.pointerType === 'touch') {
                touchStartX = event.clientX;
            }
        }, { passive: true });
        carousel.addEventListener('pointerup', (event) => {
            if (touchStartX === null) {
                return;
            }
            const distance = event.clientX - touchStartX;
            touchStartX = null;
            if (Math.abs(distance) < 48) {
                return;
            }
            showSlide((current + (distance < 0 ? 1 : -1) + slides.length) % slides.length, true);
            schedule();
        }, { passive: true });
        document.addEventListener('visibilitychange', schedule);
        reduceMotion.addEventListener?.('change', schedule);

        schedule();
    });
})();
