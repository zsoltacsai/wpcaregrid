import assert from 'node:assert/strict';
import test from 'node:test';
import { mountGraphiteWave } from '../../resources/js/components/graphite-wave.js';

function createHarness({ width = 1200, reduced = false } = {}) {
    const calls = { stroke: 0, raf: new Map(), cancelled: [], listeners: new Map(), observer: null };
    let nextHandle = 1;
    const gradient = { addColorStop() {} };
    const context = {
        clearRect() {}, setTransform() {}, beginPath() {}, moveTo() {}, lineTo() {},
        createLinearGradient() { return gradient; }, stroke() { calls.stroke += 1; },
    };
    const container = { getBoundingClientRect: () => ({ width, height: 360 }) };
    const canvas = { parentElement: container, getContext: () => context, width: 0, height: 0 };
    const documentRef = {
        hidden: false,
        querySelector: () => canvas,
        addEventListener(name, handler) { calls.listeners.set(name, handler); },
        removeEventListener(name) { calls.listeners.delete(name); },
    };
    const motionPreference = {
        matches: reduced,
        addEventListener(_name, handler) { calls.motionChange = handler; },
        removeEventListener() { calls.motionChange = null; },
    };
    class IntersectionObserverMock {
        constructor(callback) { this.callback = callback; calls.observer = this; }
        observe() { this.callback([{ isIntersecting: true }]); }
        disconnect() { this.disconnected = true; }
    }
    class ResizeObserverMock {
        constructor(callback) { this.callback = callback; calls.resizeObserver = this; }
        observe() {}
        disconnect() { this.disconnected = true; }
    }
    const windowRef = {
        devicePixelRatio: 2,
        IntersectionObserver: IntersectionObserverMock,
        ResizeObserver: ResizeObserverMock,
        matchMedia: () => motionPreference,
        requestAnimationFrame(callback) {
            const handle = nextHandle++;
            calls.raf.set(handle, callback);
            return handle;
        },
        cancelAnimationFrame(handle) {
            calls.cancelled.push(handle);
            calls.raf.delete(handle);
        },
    };

    return { calls, documentRef, windowRef, motionPreference, canvas, cleanup: null };
}

test('draws a static hero when reduced motion is requested and caps canvas pixel density', () => {
    const harness = createHarness({ reduced: true });
    harness.cleanup = mountGraphiteWave(harness);

    assert.ok(harness.calls.stroke > 0);
    assert.equal(harness.calls.raf.size, 0);
    assert.equal(harness.canvas.width, 1500, 'DPR is capped at 1.25.');
    harness.cleanup();
    assert.equal(harness.calls.observer.disconnected, true);
});

test('uses fewer ribbon paths on mobile and pauses when the hero is out of view or the tab is hidden', () => {
    const desktop = createHarness({ width: 1200, reduced: true });
    const mobile = createHarness({ width: 375, reduced: true });
    mountGraphiteWave(desktop);
    mountGraphiteWave(mobile);
    assert.ok(mobile.calls.stroke < desktop.calls.stroke);

    const animated = createHarness();
    animated.cleanup = mountGraphiteWave(animated);
    assert.equal(animated.calls.raf.size, 1);
    const [handle, frame] = animated.calls.raf.entries().next().value;
    animated.calls.raf.delete(handle);
    frame(64);
    assert.equal(animated.calls.raf.size, 1, 'Visible animation schedules one bounded frame at a time.');

    animated.calls.observer.callback([{ isIntersecting: false }]);
    assert.equal(animated.calls.raf.size, 0);
    assert.ok(animated.calls.cancelled.length > 0);

    animated.documentRef.hidden = true;
    animated.calls.observer.callback([{ isIntersecting: true }]);
    assert.equal(animated.calls.raf.size, 0, 'A hidden tab does not restart animation.');

    animated.documentRef.hidden = false;
    animated.calls.listeners.get('visibilitychange')();
    assert.equal(animated.calls.raf.size, 1);
    animated.cleanup();
    assert.equal(animated.calls.raf.size, 0);
});
