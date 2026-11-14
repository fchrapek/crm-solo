import { lazy, Suspense, useState, useSyncExternalStore } from 'react';
import { DogStill, type DogPose } from '../dog-still';
import styles from './helper.module.css';

const DogModel = lazy(() => import('./dog-model'));

const NAVY = [10, 20, 70] as const;

const noSubscription = () => () => {};

let webgl: boolean | null = null;

/** Probed once: every probe opens a WebGL context, and a browser drops the oldest ones past its limit, the dog's own among them. */
function hasWebgl(): boolean {
    if (webgl === null) {
        try {
            webgl = document.createElement('canvas').getContext('webgl2') !== null;
        } catch {
            webgl = false;
        }
    }

    return webgl;
}

/** Whether the 3D dog may run here: a browser with WebGL whose user has not asked for less motion. */
export function canAnimate(): boolean {
    return !window.matchMedia('(prefers-reduced-motion: reduce)').matches && hasWebgl();
}

/**
 * Figma: Dog, DogLook. The still picture of the pose first and wherever the model cannot run; with live the 3D head from the landing
 * page loads in its own chunk and takes over once it has drawn. Fluid fills its parent (size is then the drawing's resolution) and
 * reads the pointer against its own middle; shade is the ground shadow's colour on a coloured page, null for no shadow.
 */
export function Dog({
    size,
    live = false,
    peek = false,
    pose = 'cool',
    fluid = false,
    shade = NAVY,
}: {
    size: number;
    live?: boolean;
    peek?: boolean;
    pose?: DogPose;
    fluid?: boolean;
    shade?: readonly [number, number, number] | null;
}) {
    const animate = useSyncExternalStore(noSubscription, canAnimate, () => false);
    const [drawn, setDrawn] = useState(false);

    return (
        <span className={styles.dog} data-fluid={fluid ? '' : undefined} style={fluid ? undefined : { width: size, height: size }}>
            {!drawn && <DogStill size={size} pose={pose} />}
            {live && animate && (
                <Suspense fallback={null}>
                    <DogModel size={size} peek={peek} pose={pose} origin={fluid ? 'self' : 'corner'} shade={shade} onDrawn={() => setDrawn(true)} />
                </Suspense>
            )}
        </span>
    );
}
