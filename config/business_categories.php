<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Transaction category payment codes
    |--------------------------------------------------------------------------
    |
    | Seeded business transactions use these codes as payment_code values.
    | The frontend maps paymentCode to the matching category and icon.
    |
    */
    'personal_code' => '0000',

    'categories' => [
        '5411' => [
            'key' => 'groceries',
            'label' => 'Groceries',
            'icon_key' => 'shopping-basket',
            'payment_purposes' => ['Kupovina namirnica', 'Market', 'Supermarket'],
        ],
        '5812' => [
            'key' => 'restaurants',
            'label' => 'Restaurants',
            'icon_key' => 'utensils',
            'payment_purposes' => ['Restoran', 'Rucak', 'Dostava hrane'],
        ],
        '5541' => [
            'key' => 'fuel',
            'label' => 'Fuel',
            'icon_key' => 'fuel',
            'payment_purposes' => ['Gorivo', 'Benzinska stanica', 'Putni troskovi'],
        ],
        '4900' => [
            'key' => 'utilities',
            'label' => 'Utilities',
            'icon_key' => 'bolt',
            'payment_purposes' => ['Racun za struju', 'Komunalije', 'Racun za grejanje'],
        ],
        '4814' => [
            'key' => 'telecom',
            'label' => 'Telecom',
            'icon_key' => 'smartphone',
            'payment_purposes' => ['Mobilni racun', 'Internet', 'Telekom usluge'],
        ],
        '4111' => [
            'key' => 'transport',
            'label' => 'Transport',
            'icon_key' => 'bus',
            'payment_purposes' => ['Prevoz', 'Karta', 'Taksi voznja'],
        ],
        '5912' => [
            'key' => 'pharmacy',
            'label' => 'Pharmacy',
            'icon_key' => 'pill',
            'payment_purposes' => ['Apoteka', 'Lekovi', 'Zdravstveni proizvodi'],
        ],
        '5691' => [
            'key' => 'clothing',
            'label' => 'Clothing',
            'icon_key' => 'shirt',
            'payment_purposes' => ['Odeca', 'Obuca', 'Modni dodaci'],
        ],
        '5732' => [
            'key' => 'electronics',
            'label' => 'Electronics',
            'icon_key' => 'monitor-smartphone',
            'payment_purposes' => ['Elektronika', 'Racunarska oprema', 'Servis uredjaja'],
        ],
        '7997' => [
            'key' => 'fitness',
            'label' => 'Fitness',
            'icon_key' => 'dumbbell',
            'payment_purposes' => ['Teretana', 'Clanarina', 'Sportske aktivnosti'],
        ],
    ],
];
