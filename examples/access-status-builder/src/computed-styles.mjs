// Access Status Card Builder excerpt from Dastresi-Ban 2.0.0.
// The factory supplies the editor class and its existing style defaults.
export function registerComputedStyles(AccessStatusBuilder, styleDefaults) {
    const DEVICES = ['desktop', 'tablet', 'mobile'];
    const isObject = value => value !== null && typeof value === 'object' && !Array.isArray(value);

    AccessStatusBuilder.prototype.computedStyles = function () {
        var template = typeof this.editorLayerTemplate === 'function' ? this.editorLayerTemplate() : this.effectiveTemplate(true);
        var styles = template && isObject(template.styles) ? template.styles : {};
        var result = Object.assign({}, styleDefaults);
        var index = DEVICES.indexOf(this.device);
        if (index < 0) { index = 0; }
        for (var i = 0; i <= index; i += 1) {
            if (isObject(styles[DEVICES[i]])) { Object.assign(result, styles[DEVICES[i]]); }
        }
        if (this.isOrientationVariant()) {
            var orientation = this.orientationOverride(false);
            if (orientation && isObject(orientation.styles)) {
                for (var j = 0; j <= index; j += 1) { if (isObject(orientation.styles[DEVICES[j]])) { Object.assign(result, orientation.styles[DEVICES[j]]); } }
            }
        }
        return result;
    };
}
