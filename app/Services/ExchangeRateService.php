<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ExchangeRateService
{
    public function rates(string $base): array
    {
        $base = strtoupper($base);
        $currencies = config('exchange_rates.currencies');

        if (! isset($currencies[$base])) {
            throw new RuntimeException("Unsupported currency: {$base}");
        }

        $quotes = array_values(array_diff(array_keys($currencies), [$base]));
        $marketRates = $this->marketRates($base, $quotes);

        return array_map(function (string $quote) use ($base, $currencies, $marketRates): array {
            $middle = 1 / $marketRates[$quote];
            $spread = (float) config('exchange_rates.spread');

            return [
                'base' => $base,
                'quote' => $quote,
                'name' => $currencies[$quote]['name'],
                'countryCode' => $currencies[$quote]['countryCode'],
                'date' => $marketRates['date'],
                'buy' => round($middle * (1 - $spread), 4),
                'middle' => round($middle, 4),
                'sell' => round($middle * (1 + $spread), 4),
            ];
        }, $quotes);
    }

    public function transferValues(float $recipientAmount, string $senderCurrency, string $recipientCurrency): array
    {
        if ($senderCurrency === $recipientCurrency) {
            return [
                'senderAmount' => round($recipientAmount, 2),
                'exchangeRate' => 1.0,
            ];
        }

        $marketRate = $this->marketRates($senderCurrency, [$recipientCurrency])[$recipientCurrency];
        $sellRate = (1 / $marketRate) * (1 + (float) config('exchange_rates.spread'));
        $senderAmount = round($recipientAmount * $sellRate, 2);

        return [
            'senderAmount' => $senderAmount,
            'exchangeRate' => round(1 / $sellRate, 6),
        ];
    }

    public function transferFromSenderAmount(float $senderAmount, string $senderCurrency, string $recipientCurrency): array
    {
        if ($senderCurrency === $recipientCurrency) {
            return [
                'recipientAmount' => round($senderAmount, 2),
                'exchangeRate' => 1.0,
            ];
        }

        $marketRate = $this->marketRates($senderCurrency, [$recipientCurrency])[$recipientCurrency];
        $exchangeRate = $marketRate / (1 + (float) config('exchange_rates.spread'));

        return [
            'recipientAmount' => round($senderAmount * $exchangeRate, 2),
            'exchangeRate' => round($exchangeRate, 6),
        ];
    }

    public function convertAtMarket(float $amount, string $fromCurrency, string $toCurrency): float
    {
        if ($fromCurrency === $toCurrency) {
            return round($amount, 2);
        }

        return round($amount * $this->marketRates($fromCurrency, [$toCurrency])[$toCurrency], 2);
    }

    private function marketRates(string $base, array $quotes): array
    {
        sort($quotes);
        $cacheKey = 'frankfurter.rates.'.strtoupper($base).'.'.implode(',', $quotes);

        return Cache::remember($cacheKey, (int) config('exchange_rates.cache_seconds'), function () use ($base, $quotes): array {
            $response = Http::acceptJson()
                ->timeout(10)
                ->get(config('exchange_rates.api_url').'/rates', [
                    'base' => strtoupper($base),
                    'quotes' => implode(',', $quotes),
                ])
                ->throw()
                ->json();

            $rates = [];
            foreach ($response as $rate) {
                if (isset($rate['quote'], $rate['rate'], $rate['date'])) {
                    $rates[$rate['quote']] = (float) $rate['rate'];
                    $rates['date'] = $rate['date'];
                }
            }

            foreach ($quotes as $quote) {
                if (! isset($rates[$quote]) || $rates[$quote] <= 0) {
                    throw new RuntimeException("Frankfurter did not return a rate for {$base}/{$quote}");
                }
            }

            return $rates;
        });
    }
}
