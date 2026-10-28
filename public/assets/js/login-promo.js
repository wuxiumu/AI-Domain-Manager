'use strict';
(() => {
    const buttons = [...document.querySelectorAll('[data-promo-select]')];
    const slides = [...document.querySelectorAll('[data-promo-slide]')];
    const key = 'asset-workspace:login-poster';
    function select(index, persist = true) {
        if (!buttons[index] || !slides[index]) return;
        slides.forEach((slide, i) => { slide.hidden = i !== index; });
        buttons.forEach((button, i) => button.setAttribute('aria-pressed', String(i === index)));
        if (persist) { try { localStorage.setItem(key, String(index)); } catch (_) {} }
    }
    buttons.forEach((button, index) => button.addEventListener('click', () => select(index)));
    try { const saved = localStorage.getItem(key); if (saved !== null && /^[0-2]$/.test(saved)) select(Number(saved), false); } catch (_) {}
})();
