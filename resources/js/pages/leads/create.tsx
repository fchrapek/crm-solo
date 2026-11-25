import { Head, useForm } from '@inertiajs/react';
import React from 'react';
import { useTranslation } from 'react-i18next';

import { fieldControl, labelId, SoloField, SoloFieldRow, SoloInput, SoloTextarea } from '@/components/solo/field';
import { SoloButton, SoloButtonLink } from '@/components/solo/primitives';
import { ResponsiveSelect } from '@/components/solo/responsive-select';
import { type PlaceResolver } from '@/components/solo/shell/place';
import { SoloSurface } from '@/components/solo/surface';
import { Tile, TileGrid, TileHeader, TileNote, TileStack } from '@/components/solo/tile';
import leads from '@/routes/leads';
import { SharedData } from '@/types';

import styles from './form.module.css';
import { useLeadLabel, type LabelMap, type PipelineLabels } from './lib/labels';
import { leadPlace } from './place';

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

/** Figma: Leads · Capture lead (1094:36131, phone 1094:36361). */
export default function Create({ pipelines, sources, source_labels, pipeline }: CreatePageProps) {
    const { t } = useTranslation();
    const label = useLeadLabel();

    const { data, setData, post, processing, errors } = useForm({
        pipeline: pipeline || pipelines[0]?.key || '',
        source: '',
        name: '',
        company: '',
        email: '',
        phone: '',
        notes: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        post(leads.store().url);
    };

    return (
        <SoloSurface day="paper">
            <Head title={t('Capture lead')} />
            <TileGrid>
                <TileStack>
                    <Tile kind="plain">
                        <form className={styles.form} onSubmit={submit} data-fill="">
                            <h1 className={styles.title}>{t('Capture lead')}</h1>

                            {pipelines.length > 1 && (
                                <SoloField label={t('Brand')} htmlFor="pipeline" error={errors.pipeline}>
                                    <div className={styles.track} role="group" aria-labelledby={labelId('pipeline')}>
                                        {pipelines.map((p) => (
                                            <button key={p.key} type="button" className={styles.option} aria-pressed={data.pipeline === p.key} onClick={() => setData('pipeline', p.key)}>
                                                {label('pipeline', p.key, p.label)}
                                            </button>
                                        ))}
                                    </div>
                                    <p className={styles.hint}>{t('The funnel cannot be changed after capture.')}</p>
                                </SoloField>
                            )}

                            <SoloField label={t('Source')} htmlFor="source" error={errors.source}>
                                <ResponsiveSelect
                                    label={t('Source')}
                                    value={data.source}
                                    onValueChange={(value) => setData('source', value)}
                                    options={sources.map((source) => ({ value: source, label: label('source', source, source_labels?.[source]) }))}
                                    placeholder={t('Where did this lead come from?')}
                                    control={fieldControl('source', errors.source)}
                                />
                                {/* The plan's hard rule, said where the choice is made rather than as an error after the fact. */}
                                <p className={styles.hint}>{t('Set once, at capture - an untagged channel does not exist. This cannot be edited later.')}</p>
                            </SoloField>

                            <SoloFieldRow>
                                <SoloField label={t('Name')} htmlFor="name" error={errors.name}>
                                    <SoloInput {...fieldControl('name', errors.name)} value={data.name} onChange={(e) => setData('name', e.target.value)} />
                                </SoloField>
                                <SoloField label={t('Company')} htmlFor="company" error={errors.company}>
                                    <SoloInput {...fieldControl('company', errors.company)} value={data.company} onChange={(e) => setData('company', e.target.value)} />
                                </SoloField>
                            </SoloFieldRow>

                            <SoloFieldRow>
                                <SoloField label={t('Email')} htmlFor="email" error={errors.email}>
                                    <SoloInput {...fieldControl('email', errors.email)} type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                                </SoloField>
                                <SoloField label={t('Phone')} htmlFor="phone" error={errors.phone}>
                                    <SoloInput {...fieldControl('phone', errors.phone)} value={data.phone} onChange={(e) => setData('phone', e.target.value)} />
                                </SoloField>
                            </SoloFieldRow>

                            <SoloField label={t('Notes')} htmlFor="notes" error={errors.notes}>
                                <SoloTextarea {...fieldControl('notes', errors.notes)} rows={4} value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                            </SoloField>

                            <div className={styles.actions}>
                                <SoloButton type="submit" size="control" disabled={processing}>
                                    {t('Capture lead')}
                                </SoloButton>
                                <SoloButtonLink href={leads.index().url} variant="quiet" size="control">
                                    {t('Cancel')}
                                </SoloButtonLink>
                            </div>
                        </form>
                    </Tile>
                </TileStack>
                <TileStack as="aside">
                    <Tile kind="plain">
                        <TileHeader label={t('What happens next')} />
                        <TileNote>{t('lead_capture_note')}</TileNote>
                    </Tile>
                </TileStack>
            </TileGrid>
        </SoloSurface>
    );
}

Create.place = ((_props, context) => leadPlace(context.t('New lead'), context)) satisfies PlaceResolver<CreatePageProps>;
