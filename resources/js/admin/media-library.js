export default ($wire) => {
    return {
        lastSelectedIndex: null,
        shiftPressed: false,
        hoverIndex: null,
        init() {
            window.addEventListener("keydown", (e) => {
                if (e.key === "Shift") this.shiftPressed = true;
            });
            window.addEventListener("keyup", (e) => {
                if (e.key === "Shift") this.shiftPressed = false;
            });
        },
        async select(id, index, event) {
            this.shiftPressed = event.shiftKey;

            if (this.shiftPressed && this.lastSelectedIndex !== null) {
                const start = Math.min(this.lastSelectedIndex, index);
                const end = Math.max(this.lastSelectedIndex, index);

                const ids = [];
                const elements = this.$root.querySelectorAll("[data-media-id]");

                elements.forEach((el) => {
                    const i = parseInt(el.dataset.index);
                    if (!isNaN(i) && i >= start && i <= end) {
                        const mId = parseInt(el.dataset.mediaId);
                        if (!isNaN(mId)) ids.push(mId);
                    }
                });

                if (ids.length > 0) {
                    await $wire.selectMediaRange(ids);
                }

                this.lastSelectedIndex = index;
                return;
            }

            this.lastSelectedIndex = index;
            await $wire.selectMediaById(id);
        },
        isInRange(index) {
            if (
                !this.shiftPressed ||
                this.lastSelectedIndex === null ||
                this.hoverIndex === null
            )
                return false;
            const start = Math.min(this.lastSelectedIndex, this.hoverIndex);
            const end = Math.max(this.lastSelectedIndex, this.hoverIndex);
            return index >= start && index <= end;
        },
    };
};
