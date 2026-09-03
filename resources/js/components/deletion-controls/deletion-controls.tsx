import { Trash } from 'lucide-react';
import React from 'react';
import { useTranslation } from 'react-i18next';

import { Alert, AlertDescription } from '@/components/ui/alert';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';

import styles from './deletion-controls.module.css';

export interface DeletionControlsProps {
    resourceType: 'contact' | 'client' | 'user';
    isDeleted: boolean;
    processing: boolean;
    onAction: (e: React.MouseEvent<HTMLButtonElement>) => Promise<void>;
}

export const DeletionControls: React.FC<DeletionControlsProps> = ({ resourceType, isDeleted, processing, onAction }) => {
    const { t } = useTranslation();

    const getResourceName = () => {
        return t(resourceType.charAt(0).toUpperCase() + resourceType.slice(1));
    };

    const resourceName = getResourceName();

    return (
        <AlertDialog>
            {isDeleted ? (
                <Alert className={styles.deletedAlert}>
                    <Trash className={styles.alertIcon} />
                    <AlertDescription className={styles.alertDescription}>
                        {t(`This ${resourceType} has been deleted.`)}
                        <AlertDialogTrigger asChild>
                            <Button variant="ghost" size="sm" className={styles.restoreButton}>
                                {t('Restore')}
                            </Button>
                        </AlertDialogTrigger>
                    </AlertDescription>
                </Alert>
            ) : (
                <AlertDialogTrigger asChild>
                    <Button variant="destructive">{t(`Delete ${resourceName}`)}</Button>
                </AlertDialogTrigger>
            )}

            <AlertDialogContent>
                <AlertDialogTitle>
                    {isDeleted
                        ? t(`Are you sure you want to restore this ${resourceType}?`)
                        : t(`Are you sure you want to delete this ${resourceType}?`)}
                </AlertDialogTitle>
                <AlertDialogDescription>
                    {isDeleted
                        ? t(`This will restore the ${resourceType} and make it visible again.`)
                        : t(`The ${resourceType} will be moved to trash, but can be restored later.`)}
                </AlertDialogDescription>

                <AlertDialogFooter className={styles.footerGap}>
                    <AlertDialogCancel asChild>
                        <Button variant="secondary">{t('Cancel')}</Button>
                    </AlertDialogCancel>
                    <Button variant={isDeleted ? 'default' : 'destructive'} disabled={processing} onClick={onAction}>
                        {isDeleted ? t(`Restore ${resourceName}`) : t(`Delete ${resourceName}`)}
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
};
