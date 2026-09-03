import React, { createContext, useContext, useState } from 'react';

export interface SyncProgress {
    current: number;
    total: number;
}

interface ReverbNotificationContextType {
    pendingUuids: Set<string>;
    progressByUuid: Record<string, SyncProgress>;
    addUuid: (uuid: string) => void;
    removeUuid: (uuid: string) => void;
    setProgress: (uuid: string, current: number, total: number) => void;
}

const ReverbContext = createContext<ReverbNotificationContextType>({
    pendingUuids: new Set(),
    progressByUuid: {},
    addUuid: () => {},
    removeUuid: () => {},
    setProgress: () => {},
});

export function ReverbExampleNotificationProvider({ children }: { children: React.ReactNode }) {
    const [pendingUuids, setPendingUuids] = useState<Set<string>>(new Set());
    const [progressByUuid, setProgressByUuid] = useState<Record<string, SyncProgress>>({});

    const addUuid = (uuid: string) => {
        setPendingUuids((prev) => {
            if (!prev.has(uuid)) {
                return new Set([...prev, uuid]);
            }
            return prev;
        });
    };

    const removeUuid = (uuid: string) => {
        setPendingUuids((prev) => {
            const next = new Set(prev);
            next.delete(uuid);
            return next;
        });
        setProgressByUuid((prev) => {
            if (!(uuid in prev)) return prev;
            const next = { ...prev };
            delete next[uuid];
            return next;
        });
    };

    const setProgress = (uuid: string, current: number, total: number) => {
        setProgressByUuid((prev) => ({ ...prev, [uuid]: { current, total } }));
    };

    return <ReverbContext value={{ pendingUuids, progressByUuid, addUuid, removeUuid, setProgress }}>{children}</ReverbContext>;
}

export const useReverbNotification = () => useContext(ReverbContext);
