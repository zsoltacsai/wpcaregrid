import Alpine from 'alpinejs';
import pricingTable from './components/pricing-table';
import { mountCustomerBackupDownloads } from './components/customer-backup-download';
import { mountMarketingAnalytics } from './marketing-analytics';
import { mountGraphiteWave } from './components/graphite-wave';

Alpine.data('pricingTable', pricingTable);

window.Alpine = Alpine;
Alpine.start();

mountMarketingAnalytics();
mountGraphiteWave();
mountCustomerBackupDownloads();
