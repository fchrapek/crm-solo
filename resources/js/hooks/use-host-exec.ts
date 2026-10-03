import { usePage } from '@inertiajs/react';

import { SharedData } from '@/types';

/** False where the server refuses to run processes on its host (the demo, or CRM_HOST_EXEC=false). */
export function useHostExec(): boolean {
    return usePage<SharedData>().props.host_exec;
}
