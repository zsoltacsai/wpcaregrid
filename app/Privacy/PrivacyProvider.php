<?php

namespace App\Privacy;

enum PrivacyProvider: string
{
    case Stripe = 'stripe';
    case Szamlazz = 'szamlazz';
    case ObjectStorage = 'object_storage';
    case Mail = 'mail';
    case Hosting = 'hosting';
    case Cloudflare = 'cloudflare';
    case Other = 'other';
}
