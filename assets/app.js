/*
 * Welcome to your app's main JavaScript file!
 *
 * We recommend including the built version of this JavaScript file
 * (and its CSS file) in your base layout (base.html.twig).
 */

// Bootstrap from node_modules
import 'bootstrap';

// any CSS you import will output into a single css file (app.css in this case)
import './styles/app.scss';

import * as Sentry from "@sentry/browser";
import { BrowserTracing } from "@sentry/tracing";

function applyThemePreference() {
    let stored = null;
    try {
        stored = window.localStorage?.getItem('throttleTheme') ?? null;
    } catch (e) {
        stored = null;
    }

    const preference = /^(light|dark|system)$/.test(stored || '')
        ? stored
        : (APP_CONFIG.theme_preference || 'system');
    const effective = preference === 'system'
        ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
        : preference;

    document.documentElement.dataset.themePreference = preference;
    document.documentElement.dataset.bsTheme = effective === 'dark' ? 'dark' : 'light';
}

applyThemePreference();
const themeMedia = window.matchMedia('(prefers-color-scheme: dark)');
if (themeMedia.addEventListener) {
    themeMedia.addEventListener('change', applyThemePreference);
} else if (themeMedia.addListener) {
    themeMedia.addListener(applyThemePreference);
}

if (APP_CONFIG.sentry_dsn !== null) {
    Sentry.init({
        dsn: APP_CONFIG.sentry_dsn,
        environment: APP_CONFIG.environment,
        // release: APP_CONFIG.sentry_release,
        tunnel: APP_CONFIG.sentry_tunnel,

        integrations: [new BrowserTracing()],

        // Set tracesSampleRate to 1.0 to capture 100%
        // of transactions for performance monitoring.
        // We recommend adjusting this value in production
        tracesSampleRate: 1.0,
    });

    Sentry.setUser(APP_CONFIG.sentry_user);
}

// start the Stimulus application
import './bootstrap';

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-copy-token]');
    if (!(button instanceof HTMLButtonElement)) {
        return;
    }

    const input = document.querySelector(button.dataset.copyToken);
    if (!(input instanceof HTMLInputElement) && !(input instanceof HTMLTextAreaElement)) {
        return;
    }

    await navigator.clipboard.writeText(input.value);

    const original = button.innerHTML;
    button.textContent = 'Copied';
    button.disabled = true;

    window.setTimeout(() => {
        button.innerHTML = original;
        button.disabled = false;
    }, 1200);
});

function resizeTextarea(textarea) {
    textarea.style.height = 'auto';
    textarea.style.height = `${textarea.scrollHeight + 2}px`;
}

function generateCoreConfig(container) {
    const field = (name) => container.querySelector(`[data-core-field="${name}"]`)?.value?.trim() ?? '';
    const token = container.dataset.token ?? '';
    const baseUrl = field('baseUrl').replace(/\/+$/, '');
    const output = container.querySelector('#core-cfg');

    if (!(output instanceof HTMLTextAreaElement)) {
        return;
    }

    output.value = [
        `"MinidumpAccount" "${field('account') || 'YOUR_STEAMID64'}"`,
        '',
        `"MinidumpSymbolUpload" "${field('symbolUpload') || '3'}"`,
        `"MinidumpBinaryUpload" "${field('binaryUpload') || 'yes'}"`,
        `"MinidumpPresubmit" "${field('presubmit') || 'yes'}"`,
        '',
        `"MinidumpUrl" "${baseUrl}/submit?token=${token}"`,
        `"MinidumpSymbolUrl" "${baseUrl}/symbols/submit?token=${token}"`,
        `"MinidumpBinaryUrl" "${baseUrl}/binary/submit?token=${token}"`,
    ].join('\n');

    resizeTextarea(output);
}

document.querySelectorAll('[data-autosize-textarea]').forEach((textarea) => {
    if (textarea instanceof HTMLTextAreaElement) {
        resizeTextarea(textarea);
    }
});

document.querySelectorAll('[data-core-config-generator]').forEach((container) => {
    generateCoreConfig(container);

    container.querySelector('[data-core-generate]')?.addEventListener('click', () => {
        generateCoreConfig(container);
    });
});
