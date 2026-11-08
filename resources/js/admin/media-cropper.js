import Cropper from "cropperjs";

// Crops a single media item across one or more named variants (e.g. desktop,
// mobile). Selection geometry is read back from the rendered DOM as ratios so
// it stays accurate regardless of the resolution the editing image is shown
// at, then mapped onto the media's natural pixel dimensions for the
// server-side ImageService. Each variant's crop is staged in `pending` and
// the whole set is committed together when the user clicks Update.
export default ($wire) => {
    return {
        cropper: null,
        open: false,
        index: null,
        item: null,
        variants: [],
        activeVariant: null,
        pending: {},
        dims: { w: 0, h: 0 },

        start(index, item, crops) {
            this.index = index;
            this.item = item;
            this.variants = Object.entries(crops ?? {}).map(([key, def]) => ({
                key,
                label: def.label ?? key,
                w: def.w ?? null,
                h: def.h ?? null,
                ratio: def.ratio ?? (def.w && def.h ? def.w / def.h : null),
                q: def.q ?? 80,
                fm: def.fm ?? "jpg",
            }));
            this.pending = { ...(item?.crop ?? {}) };
            this.activeVariant = this.variants[0]?.key ?? null;
            this.open = true;

            this.$nextTick(() => this.mount());
        },

        current() {
            return (
                this.variants.find((v) => v.key === this.activeVariant) ?? null
            );
        },

        switchTo(key) {
            if (key === this.activeVariant) {
                return;
            }

            this.capture();
            this.activeVariant = key;
            this.applyVariant();
        },

        mount() {
            const image = this.$refs.cropImage;

            if (!image) {
                return;
            }

            this.teardown();

            this.cropper = new Cropper(image);

            const canvas = this.cropper.getCropperCanvas();

            if (canvas) {
                // Full width of the modal, with the height following the
                // image's aspect ratio but never taller than 600px.
                const naturalWidth = Number(this.item?.width) || 0;
                const naturalHeight = Number(this.item?.height) || 0;
                const containerWidth =
                    canvas.parentElement?.clientWidth || canvas.clientWidth;

                const fitHeight =
                    naturalWidth && naturalHeight && containerWidth
                        ? (containerWidth * naturalHeight) / naturalWidth
                        : 600;

                canvas.style.width = "100%";
                canvas.style.height =
                    Math.min(Math.round(fitHeight), 600) + "px";
            }

            const selection = this.cropper.getCropperSelection();

            if (selection) {
                selection.initialCoverage = 0.8;
                selection.addEventListener("change", () => this.updateDims());
            }

            const cropperImage = this.cropper.getCropperImage();

            if (cropperImage) {
                cropperImage.$ready().then(() => {
                    cropperImage.$center("contain");
                    this.applyVariant();
                });
            }
        },

        // Applies the active variant's aspect ratio to the existing cropper
        // and restores that variant's saved selection (or resets to a default
        // when nothing is saved). Reused on open and on tab switch, so each
        // variant keeps its own crop without recreating the cropper.
        applyVariant() {
            const selection = this.cropper?.getCropperSelection();
            const variant = this.current();

            if (!selection) {
                return;
            }

            if (variant?.ratio) {
                selection.aspectRatio = variant.ratio;
            }

            const saved = this.pending?.[this.activeVariant];

            if (
                !this.restoreSelection(selection, saved) &&
                !this.centerSelection(selection)
            ) {
                selection.$reset();
            }

            this.updateDims();
        },

        // Places the largest selection of the active variant's aspect ratio
        // that fits inside the displayed image, centered on it (so it spans
        // either the full width or the full height).
        centerSelection(selection) {
            const image = this.cropper?.getCropperImage();
            const canvas = this.cropper?.getCropperCanvas();
            const variant = this.current();

            if (!image || !canvas) {
                return false;
            }

            const imageRect = image.getBoundingClientRect();
            const canvasRect = canvas.getBoundingClientRect();

            if (!imageRect.width || !imageRect.height) {
                return false;
            }

            const imageAspect = imageRect.width / imageRect.height;
            const ratio = variant?.ratio || imageAspect;

            let width;
            let height;

            if (ratio >= imageAspect) {
                width = imageRect.width;
                height = width / ratio;
            } else {
                height = imageRect.height;
                width = height * ratio;
            }

            selection.$change(
                imageRect.left -
                    canvasRect.left +
                    (imageRect.width - width) / 2,
                imageRect.top -
                    canvasRect.top +
                    (imageRect.height - height) / 2,
                width,
                height,
            );

            return true;
        },

        get ratioLabel() {
            const ratio = this.current()?.ratio;

            return ratio ? ratio.toFixed(2) : "—";
        },

        // Re-projects a saved crop (stored in natural image pixels) back onto
        // the current on-screen image so the previous selection is visible
        // when the cropper is reopened. Returns false when there is nothing
        // to restore.
        restoreSelection(selection, saved) {
            const image = this.cropper?.getCropperImage();
            const canvas = this.cropper?.getCropperCanvas();
            const naturalWidth = Number(this.item?.width) || 0;
            const naturalHeight = Number(this.item?.height) || 0;

            if (
                !image ||
                !canvas ||
                !naturalWidth ||
                !naturalHeight ||
                !saved?.crop_w ||
                !saved?.crop_h
            ) {
                return false;
            }

            const imageRect = image.getBoundingClientRect();
            const canvasRect = canvas.getBoundingClientRect();
            const scaleX = imageRect.width / naturalWidth;
            const scaleY = imageRect.height / naturalHeight;

            selection.$change(
                imageRect.left - canvasRect.left + saved.crop_x * scaleX,
                imageRect.top - canvasRect.top + saved.crop_y * scaleY,
                saved.crop_w * scaleX,
                saved.crop_h * scaleY,
            );

            return true;
        },

        teardown() {
            if (this.cropper) {
                this.cropper.destroy();
                this.cropper = null;
            }
        },

        close() {
            this.teardown();
            this.open = false;
            this.index = null;
            this.item = null;
            this.variants = [];
            this.activeVariant = null;
            this.pending = {};
            this.dims = { w: 0, h: 0 };
        },

        computeCrop() {
            const selection = this.cropper?.getCropperSelection();
            const image = this.cropper?.getCropperImage();
            const variant = this.current();

            if (!selection || !image || !variant) {
                return null;
            }

            const imageRect = image.getBoundingClientRect();
            const selectionRect = selection.getBoundingClientRect();

            if (!imageRect.width || !imageRect.height) {
                return null;
            }

            const naturalWidth = Number(this.item?.width) || imageRect.width;
            const naturalHeight = Number(this.item?.height) || imageRect.height;

            const clamp = (value, max) =>
                Math.max(0, Math.min(Math.round(value), max));

            const cropX = clamp(
                ((selectionRect.left - imageRect.left) / imageRect.width) *
                    naturalWidth,
                naturalWidth,
            );
            const cropY = clamp(
                ((selectionRect.top - imageRect.top) / imageRect.height) *
                    naturalHeight,
                naturalHeight,
            );
            const cropW = clamp(
                (selectionRect.width / imageRect.width) * naturalWidth,
                naturalWidth - cropX,
            );
            const cropH = clamp(
                (selectionRect.height / imageRect.height) * naturalHeight,
                naturalHeight - cropY,
            );

            if (cropW <= 0 || cropH <= 0) {
                return null;
            }

            return {
                crop_w: cropW,
                crop_h: cropH,
                crop_x: cropX,
                crop_y: cropY,
                w: variant.w,
                h: variant.h,
                q: variant.q,
                fm: variant.fm,
            };
        },

        updateDims() {
            const crop = this.computeCrop();

            if (crop) {
                this.dims = { w: crop.crop_w, h: crop.crop_h };
            }
        },

        capture() {
            const crop = this.computeCrop();

            if (crop && this.activeVariant) {
                this.pending[this.activeVariant] = crop;
            }
        },

        async apply() {
            this.capture();

            if (this.index !== null) {
                await $wire.setCrops(this.index, this.pending);
            }

            this.close();
        },
    };
};
