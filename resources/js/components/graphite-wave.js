const frameInterval = 48; // Approximately 20fps; this layer is decorative only.

/** Mount the decorative, reduced-motion-aware hero canvas. */
export function mountGraphiteWave({ documentRef = globalThis.document, windowRef = globalThis.window } = {}) {
    const canvas = documentRef?.querySelector('[data-graphite-wave]');
    const container = canvas?.parentElement;
    const context = canvas?.getContext?.('2d', { alpha: true });

    if (!canvas || !container || !context || !windowRef) {
        return () => {};
    }

    const motionPreference = windowRef.matchMedia?.('(prefers-reduced-motion: reduce)') ?? { matches: false };
    // Some browsers (e.g. Safari < 14) only implement the legacy MediaQueryList
    // listener API; without this fallback those browsers never learn that the
    // user's reduced-motion preference changed.
    const addMotionListener = (handler) => {
        if (motionPreference.addEventListener) motionPreference.addEventListener('change', handler);
        else motionPreference.addListener?.(handler);
    };
    const removeMotionListener = (handler) => {
        if (motionPreference.removeEventListener) motionPreference.removeEventListener('change', handler);
        else motionPreference.removeListener?.(handler);
    };
    let width = 0;
    let height = 0;
    let elapsed = 0;
    let lastTick = 0;
    let frameHandle = 0;
    let inView = !windowRef.IntersectionObserver;
    let disposed = false;
    let ribbonLayers = [];

    // Gradient stops depend only on each ribbon's static color/alpha and the
    // strand's position, never on the animation clock or elapsed time, so
    // they are built once per resize instead of being recreated every frame.
    function buildRibbonLayers() {
        const compact = width < 768;
        const ribbons = compact
            ? [
                { count: 8, alpha: 0.14, color: '43, 59, 64', lineWidth: 0.8 },
                { count: 10, alpha: 0.17, color: '25, 41, 47', lineWidth: 0.9 },
                { count: 6, alpha: 0.1, color: '85, 97, 99', lineWidth: 0.7 },
            ]
            : [
                { count: 15, alpha: 0.16, color: '43, 59, 64', lineWidth: 0.85 },
                { count: 18, alpha: 0.19, color: '25, 41, 47', lineWidth: 1 },
                { count: 11, alpha: 0.12, color: '85, 97, 99', lineWidth: 0.75 },
            ];

        ribbonLayers = ribbons.map((ribbon) => {
            const strands = [];
            for (let strand = 0; strand < ribbon.count; strand += 1) {
                const strength = 1 - Math.abs(strand / Math.max(1, ribbon.count - 1) - 0.5) * 0.65;
                const gradient = context.createLinearGradient(0, 0, width, 0);
                gradient.addColorStop(0, `rgba(${ribbon.color}, 0)`);
                gradient.addColorStop(0.25, `rgba(${ribbon.color}, ${ribbon.alpha * 0.5 * strength})`);
                gradient.addColorStop(0.55, `rgba(${ribbon.color}, ${ribbon.alpha * strength})`);
                gradient.addColorStop(0.83, `rgba(${ribbon.color}, ${ribbon.alpha * 0.82 * strength})`);
                gradient.addColorStop(1, `rgba(${ribbon.color}, 0)`);
                strands.push({ strand, gradient });
            }
            return { count: ribbon.count, lineWidth: ribbon.lineWidth, strands };
        });
    }

    function resize() {
        const bounds = container.getBoundingClientRect();
        width = Math.max(1, bounds.width);
        height = Math.max(1, bounds.height);
        const ratio = Math.min(windowRef.devicePixelRatio || 1, 1.25);
        canvas.width = Math.round(width * ratio);
        canvas.height = Math.round(height * ratio);
        context.setTransform(ratio, 0, 0, ratio, 0, 0);
        buildRibbonLayers();
        draw(elapsed);
    }

    function strandY(x, strand, count, layer, time) {
        const u = x / width;
        const phase = u * Math.PI * 2;
        const depth = strand / Math.max(1, count - 1) - 0.5;
        const spread = 5 + Math.sin(phase * 1.1 - time * 0.18 + layer) * 3.2;
        const envelope = 0.3 + 0.7 * Math.sin(Math.PI * u) ** 1.2;
        const crest = Math.sin(phase * 1.45 - time * 0.42 + layer * 1.8) * height * 0.15;
        const fold = Math.sin(phase * 2.6 + time * 0.3 + strand * 0.08 + layer) * height * 0.045;
        const twist = Math.cos(phase * 0.72 - time * 0.24 + layer * 2.2) * depth * 26;

        return height * (0.46 + layer * 0.05) + depth * count * spread + (crest + fold + twist) * envelope;
    }

    function draw(time) {
        if (width <= 0 || height <= 0) return;

        context.clearRect(0, 0, width, height);
        context.lineCap = 'round';
        context.lineJoin = 'round';

        const pointCount = width < 768 ? 42 : 58;

        ribbonLayers.forEach((ribbon, layer) => {
            ribbon.strands.forEach(({ strand, gradient }) => {
                context.beginPath();
                for (let point = 0; point <= pointCount; point += 1) {
                    const x = width * point / pointCount;
                    const y = strandY(x, strand, ribbon.count, layer, time);
                    if (point === 0) context.moveTo(x, y);
                    else context.lineTo(x, y);
                }
                context.strokeStyle = gradient;
                context.lineWidth = ribbon.lineWidth;
                context.stroke();
            });
        });
    }

    function animate(timestamp) {
        frameHandle = 0;
        if (disposed || !inView || documentRef.hidden || motionPreference.matches) return;

        if (timestamp - lastTick >= frameInterval) {
            elapsed += Math.min(timestamp - (lastTick || timestamp), 70) / 1000;
            lastTick = timestamp;
            draw(elapsed);
        }
        frameHandle = windowRef.requestAnimationFrame(animate);
    }

    function updateMotion() {
        if (frameHandle) windowRef.cancelAnimationFrame(frameHandle);
        frameHandle = 0;
        lastTick = 0;
        draw(elapsed);
        if (!disposed && inView && !documentRef.hidden && !motionPreference.matches) {
            frameHandle = windowRef.requestAnimationFrame(animate);
        }
    }

    const intersectionObserver = windowRef.IntersectionObserver
        ? new windowRef.IntersectionObserver(([entry]) => {
            inView = entry.isIntersecting;
            updateMotion();
        }, { threshold: 0.01 })
        : null;
    intersectionObserver?.observe(container);

    const resizeObserver = windowRef.ResizeObserver
        ? new windowRef.ResizeObserver(resize)
        : null;
    resizeObserver?.observe(container);
    if (!resizeObserver) windowRef.addEventListener?.('resize', resize);
    documentRef.addEventListener?.('visibilitychange', updateMotion);
    addMotionListener(updateMotion);
    resize();
    updateMotion();

    return () => {
        disposed = true;
        if (frameHandle) windowRef.cancelAnimationFrame(frameHandle);
        intersectionObserver?.disconnect();
        resizeObserver?.disconnect();
        documentRef.removeEventListener?.('visibilitychange', updateMotion);
        removeMotionListener(updateMotion);
        if (!resizeObserver) windowRef.removeEventListener?.('resize', resize);
    };
}
