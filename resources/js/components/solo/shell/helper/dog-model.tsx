import { useEffect, useRef } from 'react';
import {
    AnimationMixer,
    CanvasTexture,
    DirectionalLight,
    Mesh,
    MeshBasicMaterial,
    NeutralToneMapping,
    PerspectiveCamera,
    PlaneGeometry,
    PMREMGenerator,
    Scene,
    SRGBColorSpace,
    WebGLRenderer,
    type AnimationAction,
    type Object3D,
} from 'three';
import { RoomEnvironment } from 'three/examples/jsm/environments/RoomEnvironment.js';
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js';
import { type DogPose } from '../dog-still';

const SRC = '/models/dog.glb';
const BASE_YAW = (-20 * Math.PI) / 180;
const PEEK = 0.5;
// the parts that lift when the helper is pointed at
const LIFT = ['Shades', 'brow_L', 'brow_R'];

const clamp = (value: number, low: number, high: number) => Math.min(high, Math.max(low, value));

const LOOK: Partial<Record<DogPose, { x: number; y: number }>> = {
    'look-left': { x: -1, y: 0 },
    'look-right': { x: 1, y: 0 },
    'look-up': { x: 0, y: -1 },
    'look-down-left': { x: -1, y: 0.7 },
};

/** Where the pointer is read against, and the colour of the shadow on the ground (r, g, b), or null for none. */
export interface DogStage {
    origin: 'corner' | 'self';
    shade: readonly [number, number, number] | null;
}

interface Stage {
    setPeek: (peek: boolean) => void;
    setPose: (pose: DogPose) => void;
    dispose: () => void;
}

/** The landing page's dog scene at a fixed square size: it follows the pointer and idles when there is none. */
function mount(host: HTMLElement, size: number, onDrawn: () => void, { origin, shade }: DogStage): Stage {
    const renderer = new WebGLRenderer({ antialias: true, alpha: true });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    renderer.outputColorSpace = SRGBColorSpace;
    renderer.toneMapping = NeutralToneMapping;
    renderer.setSize(size, size, false);
    renderer.domElement.style.cssText = 'width:100%;height:100%;display:block';
    host.appendChild(renderer.domElement);

    const scene = new Scene();
    const pmrem = new PMREMGenerator(renderer);
    scene.environment = pmrem.fromScene(new RoomEnvironment(), 0.04).texture;
    scene.environmentIntensity = 0.5;
    const camera = new PerspectiveCamera(22.1, 1, 0.1, 100);
    camera.position.set(0, 1.6, 11.5);
    camera.lookAt(0, -0.15, 0);
    const light = (colour: number, intensity: number, x: number, y: number, z: number) => {
        const l = new DirectionalLight(colour, intensity);
        l.position.set(x, y, z);
        scene.add(l);
    };
    light(0xfff1e0, 2.4, -2.5, 6.5, 3.5);
    light(0xe6edff, 0.5, 5, 1, 4);
    light(0xffffff, 1.6, 2, 4, -5);

    if (shade) {
        const blobCanvas = document.createElement('canvas');
        blobCanvas.width = blobCanvas.height = 128;
        const context = blobCanvas.getContext('2d');
        if (context) {
            const gradient = context.createRadialGradient(64, 64, 0, 64, 64, 64);
            gradient.addColorStop(0, `rgba(${shade.join(',')},1)`);
            gradient.addColorStop(1, `rgba(${shade.join(',')},0)`);
            context.fillStyle = gradient;
            context.fillRect(0, 0, 128, 128);
        }
        const blob = new Mesh(new PlaneGeometry(1, 1), new MeshBasicMaterial({ map: new CanvasTexture(blobCanvas), transparent: true, depthWrite: false, opacity: 0.35 }));
        blob.rotation.x = -Math.PI / 2;
        blob.position.set(0.35, -1.32, 0.1);
        blob.scale.set(4.2, 2.4, 1);
        scene.add(blob);
    }

    let dog: Object3D | null = null;
    let head: Object3D | null = null;
    let mixer: AnimationMixer | null = null;
    const actions: AnimationAction[] = [];
    let duration = 0;
    let frame = 0;
    let last = 0;
    let yaw = 0;
    let pitch = 0;
    let lift = 0;
    let peek = false;
    let pose: DogPose = 'cool';
    let wow = 0;
    let pointer = false;
    let gone = false;
    const aim = { x: 0, y: 0 };

    const draw = (time: number) => {
        const dt = Math.min(0.05, (time - last) / 1000 || 0);
        last = time;
        const look = LOOK[pose];
        if (!pointer && !look) {
            aim.x = Math.sin(time / 2600) * 0.5;
            aim.y = Math.sin(time / 3700) * 0.2;
        }
        const at = look ?? aim;
        const ease = 1 - Math.exp(-dt * 5);
        wow += ((pose === 'wow' ? 1 : 0) - wow) * (1 - Math.exp(-dt * 7));
        yaw += (at.x * 0.6 * (1 - wow * 0.7) - yaw) * ease;
        pitch += (at.y * 0.3 * (1 - wow) + wow * 0.24 - pitch) * ease;
        lift += (Math.max(wow, peek ? PEEK : 0) - lift) * (1 - Math.exp(-dt * 9));
        if (mixer) {
            for (const action of actions) action.time = (LIFT.includes(action.getClip().name) ? lift : wow) * duration;
            mixer.update(0);
        }
        if (dog) {
            dog.rotation.y = BASE_YAW * (1 - wow);
            dog.position.y = Math.sin(time / 600) * 0.035 + Math.sin(wow * Math.PI) * 0.14;
        }
        if (head) {
            head.rotation.y = yaw;
            head.rotation.x = pitch;
        }
        renderer.render(scene, camera);
    };

    const loop = (time: number) => {
        frame = 0;
        if (gone || document.hidden) return;
        draw(time);
        frame = requestAnimationFrame(loop);
    };
    const kick = () => {
        if (!frame && !gone) frame = requestAnimationFrame(loop);
    };

    new GLTFLoader().load(SRC, (gltf) => {
        if (gone) return;
        dog = gltf.scene;
        head = dog.getObjectByName('Head') ?? null;
        scene.add(dog);
        mixer = new AnimationMixer(dog);
        for (const clip of gltf.animations) {
            const action = mixer.clipAction(clip);
            action.play();
            action.paused = true;
            actions.push(action);
            duration = Math.max(duration, clip.duration);
        }
        draw(performance.now());
        onDrawn();
        kick();
    });

    // The helper stands in the bottom right corner, so the pointer is read against that corner; a large dog is read against its own middle.
    const follow = (event: PointerEvent) => {
        if (event.pointerType === 'touch') return;
        pointer = true;
        const box = origin === 'self' ? host.getBoundingClientRect() : null;
        const x = box ? box.left + box.width / 2 : window.innerWidth - size;
        const y = box ? box.top + box.height / 2 : window.innerHeight - size;
        aim.x = clamp((event.clientX - x) / (window.innerWidth * 0.45), -1, 1);
        aim.y = clamp((event.clientY - y) / (window.innerHeight * 0.45), -1, 1);
    };
    window.addEventListener('pointermove', follow, { passive: true });
    document.addEventListener('visibilitychange', kick);

    return {
        setPeek: (next) => {
            peek = next;
        },
        setPose: (next) => {
            pose = next;
        },
        dispose: () => {
            gone = true;
            cancelAnimationFrame(frame);
            window.removeEventListener('pointermove', follow);
            document.removeEventListener('visibilitychange', kick);
            pmrem.dispose();
            renderer.dispose();
            renderer.forceContextLoss();
            renderer.domElement.remove();
        },
    };
}

export default function DogModel({
    size,
    peek,
    pose,
    origin,
    shade,
    onDrawn,
}: {
    size: number;
    peek: boolean;
    pose: DogPose;
    origin: DogStage['origin'];
    shade: DogStage['shade'];
    onDrawn: () => void;
}) {
    const host = useRef<HTMLSpanElement>(null);
    const stage = useRef<Stage | null>(null);
    const drawn = useRef(onDrawn);
    const poseNow = useRef(pose);

    useEffect(() => {
        drawn.current = onDrawn;
    }, [onDrawn]);

    useEffect(() => {
        if (!host.current) return;
        const mounted = mount(host.current, size, () => drawn.current(), { origin, shade });
        stage.current = mounted;
        mounted.setPose(poseNow.current);

        return () => {
            stage.current = null;
            mounted.dispose();
        };
    }, [size, origin, shade]);

    useEffect(() => {
        stage.current?.setPeek(peek);
    }, [peek]);

    useEffect(() => {
        poseNow.current = pose;
        stage.current?.setPose(pose);
    }, [pose]);

    return <span ref={host} aria-hidden="true" style={{ position: 'absolute', inset: 0 }} />;
}
