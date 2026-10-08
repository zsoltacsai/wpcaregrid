<?php

namespace App\Tax;

enum SellerTaxRegistrationType: string
{
    case DomesticAam = 'domestic_aam';
    case DomesticVat = 'domestic_vat';
    case EuVat = 'eu_vat';
    case EuSmallBusiness = 'eu_small_business';
    case Oss = 'oss';
    case Other = 'other';
}
