import { router, usePage } from '@inertiajs/react';
import { type ReactNode, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';

import { Toaster } from '@/components/ui/sonner';
import { SharedData } from '@/types';
import { type SettlementFlash, settlementToast } from '@/lib/settlement-toast';
import { createFlashGate, formErrorText, isDialogOpen, summarisesFormErrors } from './flash-gate';

interface FlashMessage {
    success?: string;
    error?: string;
    settlement?: SettlementFlash | null;
}

interface FlashPageProps extends SharedData {
    flash: FlashMessage;
    errors: Record<string, string[]>;
    [key: string]: unknown;
}

const gate = createFlashGate();
let listening = false;

function listenForVisits() {
    if (listening || typeof window === 'undefined') return;
    listening = true;
    window.addEventListener('popstate', () => gate.historyRestored());
    router.on('before', () => gate.visitStarted());
}

/** A Solo page passes its own toaster so the toasts wear its surface. */
export default function FlashMessages({ toaster }: { toaster?: ReactNode }) {
    const { t, i18n } = useTranslation();
    const { flash, errors } = usePage<FlashPageProps>().props;
    const numOfErrors = Object.keys(errors).length;

    useEffect(listenForVisits, []);

    useEffect(() => {
        if (!gate.take(Boolean(flash.success || flash.error || numOfErrors > 0))) return;

        if (flash.settlement) {
            const { title, description } = settlementToast(flash.settlement, i18n.language, t);
            toast.success(title, { description });
        } else if (flash.success) {
            toast.success(flash.success);
        }

        if (flash.error) {
            toast.error(flash.error);
        } else if (summarisesFormErrors(numOfErrors, isDialogOpen())) {
            toast.error(formErrorText(errors, t('form_errors', { count: numOfErrors })));
        }
    }, [flash, errors, numOfErrors, t, i18n.language]);

    return toaster ?? <Toaster />;
}
