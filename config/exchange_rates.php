<?php

return [
    'api_url' => env('FRANKFURTER_API_URL', 'https://api.frankfurter.dev/v2'),
    'spread' => 0.05,
    'cache_seconds' => 3600,
    'currencies' => [
        'RSD' => ['name' => 'Srpski dinar', 'countryCode' => 'rs'],
        'EUR' => ['name' => 'Evro', 'countryCode' => 'eu'],
        'USD' => ['name' => 'Americki dolar', 'countryCode' => 'us'],
        'CHF' => ['name' => 'Svajcarski franak', 'countryCode' => 'ch'],
        'GBP' => ['name' => 'Britanska funta', 'countryCode' => 'gb'],
        'AUD' => ['name' => 'Australijski dolar', 'countryCode' => 'au'],
        'CAD' => ['name' => 'Kanadski dolar', 'countryCode' => 'ca'],
        'CNY' => ['name' => 'Kineski juan', 'countryCode' => 'cn'],
        'DKK' => ['name' => 'Danska kruna', 'countryCode' => 'dk'],
        'HUF' => ['name' => 'Madjarska forinta', 'countryCode' => 'hu'],
        'JPY' => ['name' => 'Japanski jen', 'countryCode' => 'jp'],
        'NOK' => ['name' => 'Norveska kruna', 'countryCode' => 'no'],
        'RUB' => ['name' => 'Ruska rublja', 'countryCode' => 'ru'],
        'SEK' => ['name' => 'Svedska kruna', 'countryCode' => 'se'],
    ],
];
