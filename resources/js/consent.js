const CONSENT_KEY = "wireup-consent";
const CONSENT_DAYS = 365;
const ran = new WeakSet();

function storedChoice() {
    try {
        const stored = JSON.parse(localStorage.getItem(CONSENT_KEY) || "null");

        if (
            stored &&
            ["accepted", "declined"].includes(stored.choice) &&
            Date.now() - stored.at < CONSENT_DAYS * 864e5
        ) {
            return stored.choice;
        }
    } catch (e) {}

    return null;
}

function store(choice) {
    try {
        localStorage.setItem(
            CONSENT_KEY,
            JSON.stringify({ choice, at: Date.now() }),
        );
    } catch (e) {}
}

async function runTemplate(template, target) {
    ran.add(template);

    for (const node of [...template.content.childNodes]) {
        if (node.nodeName !== "SCRIPT") {
            target.appendChild(node.cloneNode(true));
            continue;
        }

        const script = document.createElement("script");

        for (const { name, value } of node.attributes) {
            script.setAttribute(name, value);
        }

        script.text = node.textContent;

        const blocking =
            node.hasAttribute("src") &&
            !node.hasAttribute("async") &&
            !node.hasAttribute("defer");

        if (!blocking) {
            target.appendChild(script);
            continue;
        }

        await new Promise((resolve) => {
            script.addEventListener("load", resolve);
            script.addEventListener("error", resolve);
            target.appendChild(script);
        });
    }
}

function runAccepted() {
    if (storedChoice() !== "accepted") {
        return;
    }

    document
        .querySelectorAll('head template[data-consent="head"]')
        .forEach((template) => {
            if (!ran.has(template) && !window.wireupConsentHeadRan) {
                window.wireupConsentHeadRan = true;
                runTemplate(template, document.head);
            }
        });

    document
        .querySelectorAll('body template[data-consent="body"]')
        .forEach((template) => {
            if (!ran.has(template)) {
                runTemplate(template, document.body);
            }
        });
}

function forgetAnalyticsCookies() {
    const parts = window.location.hostname.split(".");
    const domains = parts.map((_, i) => parts.slice(i).join("."));

    document.cookie.split(";").forEach((cookie) => {
        const name = cookie.split("=")[0].trim();

        if (!name.startsWith("_ga") && name !== "_gid") {
            return;
        }

        for (const domain of ["", ...domains]) {
            document.cookie = `${name}=; Max-Age=0; path=/${domain ? `; domain=${domain}` : ""}`;
        }
    });
}

window.wireupConsent = {
    choice: storedChoice,

    accept() {
        store("accepted");
        runAccepted();
    },

    decline() {
        const hadAccepted = storedChoice() === "accepted";

        store("declined");

        if (hadAccepted) {
            forgetAnalyticsCookies();
            window.location.reload();
        }
    },

    open() {
        window.dispatchEvent(new CustomEvent("wireup-consent-open"));
    },
};

runAccepted();
document.addEventListener("livewire:navigated", runAccepted);
