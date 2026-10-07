/**
 * Pricing table state: selected product line (tab) and billing interval.
 *
 * All prices are rendered server-side from the pricing catalogue; this
 * component only toggles which ones are visible. Other components can switch
 * the tab by dispatching `pricing:select` with a product line key.
 */
export default function pricingTable({ productLines, productLine, interval, currency }) {
    return {
        productLines,
        productLine,
        interval,
        currency,

        init() {
            window.addEventListener('pricing:select', (event) => {
                this.selectProductLine(event.detail);
            });
        },

        selectProductLine(key) {
            if (this.productLines.includes(key)) {
                this.productLine = key;
            }
        },

        setInterval(interval) {
            this.interval = interval;
        },

        /** Arrow/Home/End navigation between tabs, per the WAI-ARIA tabs pattern. */
        onTabKeydown(event) {
            const index = this.productLines.indexOf(this.productLine);
            const last = this.productLines.length - 1;
            const target = {
                ArrowRight: index === last ? 0 : index + 1,
                ArrowLeft: index === 0 ? last : index - 1,
                Home: 0,
                End: last,
            }[event.key];

            if (target === undefined) {
                return;
            }

            event.preventDefault();
            this.productLine = this.productLines[target];
            this.$nextTick(() => {
                this.$root.querySelector(`[data-tab="${this.productLine}"]`)?.focus();
            });
        },
    };
}
