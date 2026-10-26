import Alpine from 'alpinejs';
import pricingTable from './components/pricing-table';
import { mountCustomerBackupDownloads } from './components/customer-backup-download';
import { mountMarketingAnalytics } from './marketing-analytics';

Alpine.data('pricingTable', pricingTable);

window.Alpine = Alpine;
Alpine.start();

mountMarketingAnalytics();
mountCustomerBackupDownloads();
