Livewire.directive("warn-dirty", ({ el, directive, component, cleanup }) => {
    const action = el.getAttribute("wire:submit");
    const message =
        directive.expression || "You have unsaved changes. Leave anyway?";

    // `locale` is a view-only toggle (which language is being edited) on translated
    // forms — it must not count toward the dirty state, or merely switching the
    // editing language would trigger the unsaved-changes warning. `showPreview` and
    // `previewToken` are transient preview UI state and likewise never count as edits.
    // A form may name further properties to ignore via `data-warn-dirty-ignore` — used
    // for fields owned by sibling save actions (e.g. integration credentials saved from
    // their own modals) that persist immediately and so are never part of this form.
    const ignored = new Set([
        "locale",
        "showPreview",
        "previewToken",
        ...(el.getAttribute("data-warn-dirty-ignore") ?? "")
            .split(",")
            .map((key) => key.trim())
            .filter(Boolean),
    ]);
    const getState = () => {
        const rest = { ...(component.ephemeral ?? {}) };
        ignored.forEach((key) => delete rest[key]);

        return JSON.stringify(rest);
    };
    let initialState = getState();
    let userEdited = false;

    // Controls like rich-text editors, input masks and entangled comboboxes
    // normalize their bound value shortly after load, drifting the state without
    // a real edit. Re-baseline once the page settles — unless the user has already
    // typed/changed something — so that drift isn't mistaken for unsaved changes.
    const markEdited = (e) => {
        if (e.isTrusted) userEdited = true;
    };
    el.addEventListener("input", markEdited, true);
    el.addEventListener("change", markEdited, true);

    const rebaseline = setTimeout(() => {
        if (!userEdited) initialState = getState();
    }, 600);

    const isDirty = () => getState() !== initialState;

    // Reset the dirty baseline after a successful submit (no validation errors).
    // A message interceptor gives onSuccess the response payload, and onRender
    // runs after the new state is merged so the baseline matches the saved state.
    component.$wire.interceptMessage(action, ({ onSuccess }) => {
        onSuccess(({ payload, onRender }) => {
            if (payload.snapshot.memo.errors.length !== 0) {
                return;
            }

            onRender(() => {
                initialState = getState();
                userEdited = false;
            });
        });
    });

    // Opening the preview is a server round-trip (it caches a snapshot) that can
    // nudge normalized/masked field values on re-render — that must not register as
    // an unsaved edit. If the form was clean when preview was requested, re-baseline
    // once it renders; if it was already dirty (real edits), leave the warning intact.
    component.$wire.interceptMessage("preview", ({ onSuccess }) => {
        const wasDirty = isDirty();

        onSuccess(({ onRender }) => {
            onRender(() => {
                if (!wasDirty) initialState = getState();
            });
        });
    });

    const handleBeforeUnload = (e) => {
        if (!isDirty()) return;
        e.preventDefault();
        e.returnValue = ""; // required for browser dialog
    };

    const handleNavigate = (e) => {
        if (isDirty() && !confirm(message)) e.preventDefault();
    };

    // Register listeners
    window.addEventListener("beforeunload", handleBeforeUnload);
    document.addEventListener("livewire:navigate", handleNavigate);

    // Clean up when component is torn down
    cleanup(() => {
        clearTimeout(rebaseline);
        el.removeEventListener("input", markEdited, true);
        el.removeEventListener("change", markEdited, true);
        window.removeEventListener("beforeunload", handleBeforeUnload);
        document.removeEventListener("livewire:navigate", handleNavigate);
    });
});
