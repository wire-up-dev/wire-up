import "./passkeys";
import "./admin/navigation";
import "./admin/warn-dirty";
import "./admin/block-items";
import "./admin/file-analysis";
import editorSource from "./admin/editor-source";
import mediaLibrary from "./admin/media-library";
import mediaCropper from "./admin/media-cropper";
import bulkSelection from "./admin/bulk-selection";

import.meta.glob(["../images/**/*"], {
    eager: true,
    query: "?url",
    import: "default",
});

// Registered through a guard rather than on `alpine:init` alone: this bundle is a
// deferred module, so on a cold cache Alpine can start before it executes and the
// event is missed — every component below then dies with "x is not defined", and
// an always-rendered element such as the crop modal shows its raw markup.
const registerAdminAlpineData = () => {
    Alpine.data("editorSource", editorSource);
    Alpine.data("mediaLibrary", mediaLibrary);
    Alpine.data("mediaCropper", mediaCropper);
    Alpine.data("bulkSelection", bulkSelection);
};

if (window.Alpine) {
    registerAdminAlpineData();
} else {
    document.addEventListener("alpine:init", registerAdminAlpineData);
}
