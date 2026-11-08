import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const tokensCss = readFileSync(new URL('../../resources/css/tokens/direction-041.css', import.meta.url), 'utf8');
const layerCss = readFileSync(new URL('../../resources/css/m16-direction-041.css', import.meta.url), 'utf8');

const defined = new Set([...tokensCss.matchAll(/^\s*(--m16-[a-z0-9-]+):/gm)].map((match) => match[1]));

test('the token file declares values only, on :root', () => {
    const withoutComments = tokensCss.replace(/\/\*[\s\S]*?\*\//g, '');
    const selectors = [...withoutComments.matchAll(/([^{}]+)\{/g)].map((match) => match[1].trim());
    assert.deepEqual(selectors, [':root']);
    assert.ok(defined.size >= 30);
});

test('every --m16 token used by the Direction 04.1 stylesheet is defined', () => {
    const used = new Set([...layerCss.matchAll(/var\((--m16-[a-z0-9-]+)/g)].map((match) => match[1]));
    assert.ok(used.size > 0);
    for (const name of used) assert.ok(defined.has(name), `${name} is used but not defined`);
});

test('the stylesheet takes brand colours from tokens, not raw hex values', () => {
    const rawHex = [...layerCss.matchAll(/#[0-9a-fA-F]{3,8}\b/g)].map((match) => match[0].toLowerCase());
    assert.deepEqual([...new Set(rawHex)], ['#000'], 'Only the mask-image alpha stop may stay literal.');
});

test('stylesheet imports the token file before any rule', () => {
    const firstStatement = layerCss.replace(/\/\*[\s\S]*?\*\//g, '').trim().split('\n')[0];
    assert.equal(firstStatement, "@import './tokens/direction-041.css';");
});
