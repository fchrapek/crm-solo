import { Row } from '@/components/solo/row';
import { Checkbox } from '@/components/ui/checkbox';
import { InfoHint } from '@/components/ui/info-hint';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';

/** One setting saved on the change, its (i) saying what it turns on; a refusal comes back as a toast and the switch stays. */
export function SettingSwitch({
    name,
    label,
    hint,
    value,
    url,
    rule = false,
}: {
    name: string;
    label: string;
    hint?: string;
    value: boolean;
    url: string;
    rule?: boolean;
}) {
    const [sending, setSending] = useState<boolean | null>(null);

    return (
        <Row
            rule={rule}
            title={
                hint === undefined ? (
                    label
                ) : (
                    <>
                        {label} <InfoHint>{hint}</InfoHint>
                    </>
                )
            }
            action={
                <Checkbox
                    variant="switch"
                    aria-label={label}
                    checked={sending ?? value}
                    disabled={sending !== null}
                    onCheckedChange={(checked) => {
                        setSending(checked === true);
                        router.patch(
                            url,
                            { [name]: checked === true },
                            {
                                preserveScroll: true,
                                onError: (errors) => errors[name] && toast.error(errors[name]),
                                onFinish: () => setSending(null),
                            },
                        );
                    }}
                />
            }
        />
    );
}
