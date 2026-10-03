import { useEffect, useRef } from 'react';

interface TurnstileRenderOptions {
    sitekey: string;
    action: string;
    callback: (token: string) => void;
    'expired-callback': () => void;
    'error-callback': () => void;
}

interface TurnstileApi {
    render: (container: HTMLElement, options: TurnstileRenderOptions) => string | undefined;
    remove: (widgetId: string) => void;
    reset: (widget?: string | HTMLElement) => void;
}

declare global {
    interface Window {
        turnstile?: TurnstileApi;
    }
}

const SCRIPT_ID = 'cf-turnstile-script';

/** Loads api.js once per document; later calls resolve straight away. */
function loadTurnstile(): Promise<TurnstileApi> {
    if (window.turnstile) {
        return Promise.resolve(window.turnstile);
    }

    return new Promise((resolve, reject) => {
        let script = document.getElementById(SCRIPT_ID) as HTMLScriptElement | null;

        if (!script) {
            script = document.createElement('script');
            script.id = SCRIPT_ID;
            script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
            script.async = true;
            document.head.appendChild(script);
        }

        script.addEventListener('load', () => (window.turnstile ? resolve(window.turnstile) : reject(new Error('Turnstile did not load'))), { once: true });
        script.addEventListener('error', () => reject(new Error('Turnstile did not load')), { once: true });
    });
}

interface TurnstileWidgetProps {
    /** Present only when the backend has TURNSTILE_SECRET configured. */
    siteKey: string;
    action: string;
    onToken: (token: string) => void;
}

/**
 * Cloudflare Turnstile rendered explicitly on every mount and removed on unmount,
 * so Inertia navigation (which keeps api.js but swaps the DOM) gets a live widget
 * each time. The token feeds the Inertia form, which submits its state, never the DOM.
 */
export function TurnstileWidget({ siteKey, action, onToken }: TurnstileWidgetProps) {
    const container = useRef<HTMLDivElement>(null);
    const tokenHandler = useRef(onToken);

    useEffect(() => {
        tokenHandler.current = onToken;
    }, [onToken]);

    useEffect(() => {
        let widgetId: string | undefined;
        let cancelled = false;

        loadTurnstile()
            .then((turnstile) => {
                if (cancelled || !container.current) {
                    return;
                }

                widgetId = turnstile.render(container.current, {
                    sitekey: siteKey,
                    action,
                    callback: (token) => tokenHandler.current(token),
                    'expired-callback': () => tokenHandler.current(''),
                    'error-callback': () => tokenHandler.current(''),
                });
            })
            .catch(() => tokenHandler.current(''));

        return () => {
            cancelled = true;

            if (widgetId !== undefined) {
                window.turnstile?.remove(widgetId);
            }
        };
    }, [siteKey, action]);

    return <div ref={container} />;
}

/** Tokens are single-use: after a rejected submit the widget must issue a fresh one. */
export function resetTurnstile(onToken: (token: string) => void): void {
    window.turnstile?.reset();
    onToken('');
}
