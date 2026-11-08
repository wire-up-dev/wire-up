document.addEventListener("livewire:navigate", (event) => {
    if (!/^\/admin(\/|$)/.test(event.detail.url.pathname)) {
        event.preventDefault();
        window.location.assign(event.detail.url.href);
    }
});

document.addEventListener("livewire:navigating", () => {
    const pauseTransitions = document.createElement("style");
    pauseTransitions.textContent =
        "*, *::before, *::after { transition: none !important; }";
    document.head.appendChild(pauseTransitions);

    const resumeTransitions = () => {
        requestAnimationFrame(() => {
            requestAnimationFrame(() => pauseTransitions.remove());
        });
    };

    document.addEventListener("livewire:navigated", resumeTransitions, {
        once: true,
    });
    setTimeout(() => pauseTransitions.remove(), 3000);
});
