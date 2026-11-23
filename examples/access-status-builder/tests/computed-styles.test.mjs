import assert from 'node:assert/strict';
import test from 'node:test';
import { registerComputedStyles } from '../src/computed-styles.mjs';

class FixtureBuilder {
	constructor(device, template, orientation = null) {
		this.device = device;
		this.template = template;
		this.orientation = orientation;
	}
	effectiveTemplate() { return this.template; }
	isOrientationVariant() { return this.orientation !== null; }
	orientationOverride() { return this.orientation; }
}

registerComputedStyles(FixtureBuilder, { spacing: 16, radius: 8, headingSize: 24 });
const template = { styles: { desktop: { spacing: 24, radius: 14 }, tablet: { spacing: 20 }, mobile: { headingSize: 20 } } };

test('Desktop, tablet, and mobile inherit settings in order', () => {
	assert.deepEqual(new FixtureBuilder('desktop', template).computedStyles(), { spacing: 24, radius: 14, headingSize: 24 });
	assert.deepEqual(new FixtureBuilder('tablet', template).computedStyles(), { spacing: 20, radius: 14, headingSize: 24 });
	assert.deepEqual(new FixtureBuilder('mobile', template).computedStyles(), { spacing: 20, radius: 14, headingSize: 20 });
});

test('Orientation overrides the displayed mobile card without changing the saved template', () => {
	const before = structuredClone(template);
	const instance = new FixtureBuilder('mobile', template, { styles: { desktop: { radius: 12 }, mobile: { spacing: 12 } } });
	assert.deepEqual(instance.computedStyles(), { spacing: 12, radius: 12, headingSize: 20 });
	assert.deepEqual(template, before);
});

test('A legacy template without styles uses the defaults', () => {
	assert.deepEqual(new FixtureBuilder('mobile', {}).computedStyles(), { spacing: 16, radius: 8, headingSize: 24 });
	assert.deepEqual(new FixtureBuilder('future-device', template).computedStyles(), { spacing: 24, radius: 14, headingSize: 24 });
});
