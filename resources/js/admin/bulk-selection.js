export default ($wire, enabled = true) => ({
    isSelected(id) {
        return $wire.selected.map(String).includes(String(id));
    },
    toggleRow(event, id) {
        if (
            !enabled ||
            event.target.closest(
                "a, button, input, label, [role=menu], [data-flux-checkbox]",
            )
        ) {
            return;
        }

        const key = String(id);
        const selected = $wire.selected.map(String);

        $wire.selected = selected.includes(key)
            ? selected.filter((value) => value !== key)
            : [...selected, key];
    },
});
