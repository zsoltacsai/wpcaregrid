<?php

namespace App\Privacy;

enum RetentionAction: string
{
    case Retain = 'retain';
    case ReportOnly = 'report_only';
    case Anonymize = 'anonymize';
    case Delete = 'delete';
}
