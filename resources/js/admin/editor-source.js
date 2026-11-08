// Toggles a raw-HTML "source" view for a Flux rich-text editor. The textarea
// reads from and writes back to the underlying Tiptap instance (captured above
// on the `flux:editor` event), so edits round-trip through the editor's own
// wire:model binding.
export default () => ({
    source: false,
    readFrom(root) {
        const instance = root?._tiptap ?? null;
        return instance ? instance.getHTML() : (root?.value ?? "");
    },
    writeTo(root, html) {
        const instance = root?._tiptap ?? null;
        if (instance) {
            instance.commands.setContent(html, true);
        } else if (root) {
            root.value = html;
        }
    },
    toggleSource(el) {
        const root = el.closest("[data-flux-editor]");
        if (!root) return;

        const area = root.querySelector("[data-editor-source]");
        const content = root.querySelector("ui-editor-content");

        this.source = !this.source;

        if (this.source) {
            area.value = this.readFrom(root);
            area.style.display = "";
            if (content) content.style.display = "none";
            area.focus();
        } else {
            this.writeTo(root, area.value);
            area.style.display = "none";
            if (content) content.style.display = "";
        }
    },
    syncSource(el) {
        if (!this.source) return;
        this.writeTo(el.closest("[data-flux-editor]"), el.value);
    },
});
