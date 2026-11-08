let pendingBlockItem = null;

window.addEventListener("open-block-item", (event) => {
    pendingBlockItem = event.detail?.id ?? null;
});

window.claimsOpenBlockItem = (id) => {
    if (pendingBlockItem === null || String(pendingBlockItem) !== String(id)) {
        return false;
    }

    pendingBlockItem = null;

    return true;
};
