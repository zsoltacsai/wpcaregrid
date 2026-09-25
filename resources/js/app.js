import Alpine from 'alpinejs';
import pricingTable from './components/pricing-table';

Alpine.data('pricingTable', pricingTable);

window.Alpine = Alpine;
Alpine.start();
