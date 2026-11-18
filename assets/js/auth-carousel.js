(function () {
    var carousel = document.querySelector('[data-auth-carousel]');
    if (!carousel) {
        return;
    }

    var slides = Array.prototype.slice.call(carousel.querySelectorAll('[data-carousel-slide]'));
    var indicators = Array.prototype.slice.call(carousel.querySelectorAll('[data-carousel-indicator]'));
    var activeIndex = 0;
    var timer = null;
    var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function showSlide(index) {
        activeIndex = index;
        slides.forEach(function (slide, slideIndex) {
            var active = slideIndex === activeIndex;
            slide.classList.toggle('is-active', active);
            slide.setAttribute('aria-hidden', active ? 'false' : 'true');
        });
        indicators.forEach(function (indicator, indicatorIndex) {
            var active = indicatorIndex === activeIndex;
            indicator.classList.toggle('is-active', active);
            indicator.setAttribute('aria-current', active ? 'true' : 'false');
        });
    }

    function stopTimer() {
        if (timer !== null) {
            window.clearInterval(timer);
            timer = null;
        }
    }

    function startTimer() {
        stopTimer();
        if (reducedMotion || document.hidden || carousel.matches(':hover') || carousel.contains(document.activeElement)) {
            return;
        }
        timer = window.setInterval(function () {
            showSlide((activeIndex + 1) % slides.length);
        }, 4500);
    }

    indicators.forEach(function (indicator, index) {
        indicator.addEventListener('click', function () {
            showSlide(index);
            startTimer();
        });
    });

    carousel.addEventListener('mouseenter', stopTimer);
    carousel.addEventListener('mouseleave', startTimer);
    carousel.addEventListener('focusin', stopTimer);
    carousel.addEventListener('focusout', function (event) {
        if (!carousel.contains(event.relatedTarget)) {
            startTimer();
        }
    });
    document.addEventListener('visibilitychange', startTimer);

    document.addEventListener('click', function (event) {
        var link = event.target.closest('a[href]');
        if (!link || reducedMotion || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || link.target) {
            return;
        }
        var destination = new URL(link.href, window.location.href);
        if (destination.origin !== window.location.origin || !/\/auth\/(login|register|forgot-password)\.php$/.test(destination.pathname) || destination.pathname === window.location.pathname) {
            return;
        }
        event.preventDefault();
        document.body.classList.add('is-leaving');
        window.setTimeout(function () {
            window.location.href = destination.href;
        }, 180);
    });

    startTimer();
}());
