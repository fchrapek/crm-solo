export type DogPose = 'cool' | 'look-left' | 'look-right' | 'look-up' | 'look-down-left' | 'wow';

const SRC: Record<DogPose, string> = {
    cool: '/images/helper/dog.png',
    'look-left': '/images/helper/dog-look-left.png',
    'look-right': '/images/helper/dog-look-right.png',
    'look-up': '/images/helper/dog-look-up.png',
    'look-down-left': '/images/helper/dog-look-left.png',
    wow: '/images/helper/dog-wow.png',
};

/** The dog's still picture: the rail logo, and the helper before its 3D model loads or where it cannot run. */
export function DogStill({ size, pose = 'cool' }: { size: number; pose?: DogPose }) {
    return <img src={SRC[pose]} width={size} height={size} alt="" draggable={false} data-pose={pose} />;
}
