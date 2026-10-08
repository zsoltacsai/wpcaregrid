<?php

namespace App\Invoicing;

/** Localized labels for the Agent's free-text payment-method field. */
final class SzamlazzPaymentMethodRegistry
{
    /** @var array<string, array<string, string>> */
    private const LABELS = [
        'hu' => ['bank_transfer' => 'átutalás', 'bank_card' => 'bankkártya', 'cash' => 'készpénz', 'other' => 'egyéb'],
        'en' => ['bank_transfer' => 'Bank transfer', 'bank_card' => 'Bank card', 'cash' => 'Cash', 'other' => 'Other'],
        'de' => ['bank_transfer' => 'Banküberweisung', 'bank_card' => 'Bankkarte', 'cash' => 'Barzahlung', 'other' => 'Sonstiges'],
        'it' => ['bank_transfer' => 'Bonifico bancario', 'bank_card' => 'Carta bancaria', 'cash' => 'Contanti', 'other' => 'Altro'],
        'ro' => ['bank_transfer' => 'Transfer bancar', 'bank_card' => 'Card bancar', 'cash' => 'Numerar', 'other' => 'Altele'],
        'sk' => ['bank_transfer' => 'Bankový prevod', 'bank_card' => 'Banková karta', 'cash' => 'Hotovosť', 'other' => 'Iné'],
        'hr' => ['bank_transfer' => 'Bankovni prijenos', 'bank_card' => 'Bankovna kartica', 'cash' => 'Gotovina', 'other' => 'Ostalo'],
        'fr' => ['bank_transfer' => 'Virement bancaire', 'bank_card' => 'Carte bancaire', 'cash' => 'Espèces', 'other' => 'Autre'],
        'es' => ['bank_transfer' => 'Transferencia bancaria', 'bank_card' => 'Tarjeta bancaria', 'cash' => 'Efectivo', 'other' => 'Otro'],
        'cz' => ['bank_transfer' => 'Bankovní převod', 'bank_card' => 'Bankovní karta', 'cash' => 'Hotovost', 'other' => 'Jiné'],
        'pl' => ['bank_transfer' => 'Przelew bankowy', 'bank_card' => 'Karta płatnicza', 'cash' => 'Gotówka', 'other' => 'Inne'],
        'bg' => ['bank_transfer' => 'Банков превод', 'bank_card' => 'Банкова карта', 'cash' => 'В брой', 'other' => 'Друго'],
        'nl' => ['bank_transfer' => 'Bankoverschrijving', 'bank_card' => 'Bankkaart', 'cash' => 'Contant', 'other' => 'Overig'],
        'ru' => ['bank_transfer' => 'Банковский перевод', 'bank_card' => 'Банковская карта', 'cash' => 'Наличные', 'other' => 'Другое'],
        'si' => ['bank_transfer' => 'Bančno nakazilo', 'bank_card' => 'Bančna kartica', 'cash' => 'Gotovina', 'other' => 'Drugo'],
    ];

    public static function label(string $method, string $language): ?string
    {
        return self::LABELS[$language][$method] ?? null;
    }
}
