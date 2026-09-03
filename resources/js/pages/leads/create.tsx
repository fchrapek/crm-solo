import { Head, useForm } from '@inertiajs/react';
import React from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { usePageActions } from '@/contexts/page-context';
import leads from '@/routes/leads';
import { BreadcrumbItem, SharedData } from '@/types';

import styles from './form.module.css';
import { useLeadLabel, type LabelMap, type PipelineLabels } from './lib/labels';

interface PipelineMeta {
    key: string;
    label: string | null;
    stages: string[];
    won_stage: string;
    labels?: PipelineLabels;
}

interface CreatePageProps extends SharedData {
    pipelines: PipelineMeta[];
    sources: string[];
    source_labels: LabelMap;
    pipeline: string;
}

export default function Create({ pipelines, sources, source_labels, pipeline }: CreatePageProps) {
    const { t } = useTranslation();
    const label = useLeadLabel();
    const { setBreadcrumbs } = usePageActions();

    const { data, setData, post, processing, errors } = useForm({
        pipeline: pipeline || pipelines[0]?.key || '',
        source: '',
        name: '',
        company: '',
        email: '',
        phone: '',
        notes: '',
    });

    const breadcrumbs: BreadcrumbItem[] = React.useMemo(
        () => [
            { title: 'Leads', href: leads.index().url },
            { title: 'Capture lead', href: leads.create().url },
        ],
        [],
    );
    React.useEffect(() => {
        setBreadcrumbs(breadcrumbs);
    }, [breadcrumbs, setBreadcrumbs]);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        post(leads.store().url);
    };

    return (
        <>
            <Head title={t('Capture lead')} />

            <form className={styles.form} onSubmit={submit}>
                <div className={styles.field}>
                    <Label htmlFor="pipeline">{t('Brand')}</Label>
                    <Select value={data.pipeline} onValueChange={(v) => setData('pipeline', v)}>
                        <SelectTrigger id="pipeline">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {pipelines.map((p) => (
                                <SelectItem key={p.key} value={p.key}>
                                    {label('pipeline', p.key, p.label)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {errors.pipeline && <p className={styles.error}>{errors.pipeline}</p>}
                    <p className={styles.hint}>{t('The funnel cannot be changed after capture.')}</p>
                </div>

                <div className={styles.field}>
                    <Label htmlFor="source">{t('Source')}</Label>
                    <Select value={data.source} onValueChange={(v) => setData('source', v)}>
                        <SelectTrigger id="source">
                            <SelectValue placeholder={t('Where did this lead come from?')} />
                        </SelectTrigger>
                        <SelectContent>
                            {sources.map((source) => (
                                <SelectItem key={source} value={source}>
                                    {label('source', source, source_labels?.[source])}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {errors.source && <p className={styles.error}>{errors.source}</p>}
                    {/* The plan's hard rule, surfaced where the choice is made
                        rather than as an error after the fact. */}
                    <p className={styles.hint}>{t('Set once, at capture - an untagged channel does not exist. This cannot be edited later.')}</p>
                </div>

                <div className={styles.field}>
                    <Label htmlFor="name">{t('Name')}</Label>
                    <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                    {errors.name && <p className={styles.error}>{errors.name}</p>}
                </div>

                <div className={styles.field}>
                    <Label htmlFor="company">{t('Company')}</Label>
                    <Input id="company" value={data.company} onChange={(e) => setData('company', e.target.value)} />
                    {errors.company && <p className={styles.error}>{errors.company}</p>}
                </div>

                <div className={styles.row}>
                    <div className={styles.field}>
                        <Label htmlFor="email">{t('Email')}</Label>
                        <Input id="email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                        {errors.email && <p className={styles.error}>{errors.email}</p>}
                    </div>
                    <div className={styles.field}>
                        <Label htmlFor="phone">{t('Phone')}</Label>
                        <Input id="phone" value={data.phone} onChange={(e) => setData('phone', e.target.value)} />
                        {errors.phone && <p className={styles.error}>{errors.phone}</p>}
                    </div>
                </div>

                <div className={styles.field}>
                    <Label htmlFor="notes">{t('Notes')}</Label>
                    <Textarea id="notes" rows={4} value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                </div>

                <div className={styles.actions}>
                    <Button type="submit" disabled={processing}>
                        {t('Capture lead')}
                    </Button>
                </div>
            </form>
        </>
    );
}
