// <button data-confirm="Question?"> asks before the click goes through.
// Replaces inline onclick="return confirm(...)", which the Content Security
// Policy blocks (ADR 0005).
document.addEventListener('click', (event) => {
    const trigger = event.target instanceof Element ? event.target.closest('[data-confirm]') : null

    if (trigger && !window.confirm(trigger.getAttribute('data-confirm') || '')) {
        event.preventDefault()
        event.stopImmediatePropagation()
    }
}, true)
