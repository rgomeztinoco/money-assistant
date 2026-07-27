<?php

namespace App\Integrations\BcrpData;

use App\Contracts\BcrpData;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;
use UnexpectedValueException;

final class HttpBcrpData implements BcrpData
{
    private const BASE_URL = 'https://estadisticas.bcrp.gob.pe/estadisticas/series/api';

    private const SERIES = 'PD04638PD';

    private const SERIES_NAME = 'Tipo de cambio - TC Interbancario (S/ por US$) - Venta';

    public function findObservation(CarbonImmutable $applicableOn): ?BcrpExchangeRateObservation
    {
        $from = $applicableOn->subDays(7);
        $response = Http::acceptJson()
            ->connectTimeout(3)
            ->timeout(10)
            ->retry(
                [200, 1000],
                when: fn (Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError()),
            )
            ->get(sprintf(
                '%s/%s/json/%s/%s/esp',
                self::BASE_URL,
                self::SERIES,
                $from->toDateString(),
                $applicableOn->toDateString(),
            ))
            ->throw();
        $retrievedAt = CarbonImmutable::now();
        $payload = $response->json();

        if (! is_array($payload)) {
            throw new UnexpectedValueException('BCRPData returned an invalid response.');
        }

        $sourcePrecision = $this->sourcePrecision($payload);
        $periods = $payload['periods'] ?? null;

        if (! is_array($periods)) {
            throw new UnexpectedValueException('BCRPData returned invalid periods.');
        }

        $observations = [];

        foreach ($periods as $period) {
            if (! is_array($period)
                || ! is_string($period['name'] ?? null)
                || ! is_array($period['values'] ?? null)
                || count($period['values']) !== 1
                || ! is_string($period['values'][0] ?? null)) {
                throw new UnexpectedValueException('BCRPData returned an invalid observation.');
            }

            $observedOn = $this->parseObservedOn($period['name']);
            $value = $period['values'][0];

            if ($value === 'n.d.' || $observedOn === null || ! $this->isPositiveDecimal($value)) {
                continue;
            }

            $observedDate = $observedOn->toDateString();

            if ($observedDate < $from->toDateString() || $observedDate > $applicableOn->toDateString()) {
                continue;
            }

            $observations[$observedDate] = new BcrpExchangeRateObservation(
                observedOn: $observedOn,
                retrievedAt: $retrievedAt,
                value: $value,
                sourcePrecision: $sourcePrecision,
            );
        }

        if ($observations === []) {
            return null;
        }

        krsort($observations);

        return array_values($observations)[0];
    }

    /** @param array<string, mixed> $payload */
    private function sourcePrecision(array $payload): int
    {
        $series = $payload['config']['series'] ?? null;

        if (! is_array($series)
            || count($series) !== 1
            || ! is_array($series[0] ?? null)
            || ($series[0]['name'] ?? null) !== self::SERIES_NAME
            || ! is_string($series[0]['dec'] ?? null)
            || preg_match('/^\d{1,2}$/D', $series[0]['dec']) !== 1) {
            throw new UnexpectedValueException('BCRPData returned an unexpected series.');
        }

        $precision = (int) $series[0]['dec'];

        if ($precision > 18) {
            throw new UnexpectedValueException('BCRPData returned invalid source precision.');
        }

        return $precision;
    }

    private function parseObservedOn(string $sourceDate): ?CarbonImmutable
    {
        if (preg_match('/^(\d{2})\.([A-Za-z]{3,4})\.(\d{2})$/D', $sourceDate, $matches) !== 1) {
            return null;
        }

        $months = [
            'Ene' => 1,
            'Jan' => 1,
            'Feb' => 2,
            'Mar' => 3,
            'Abr' => 4,
            'Apr' => 4,
            'May' => 5,
            'Jun' => 6,
            'Jul' => 7,
            'Ago' => 8,
            'Aug' => 8,
            'Set' => 9,
            'Sep' => 9,
            'Oct' => 10,
            'Nov' => 11,
            'Dic' => 12,
            'Dec' => 12,
        ];
        $month = $months[$matches[2]] ?? null;
        $year = 2000 + (int) $matches[3];
        $day = (int) $matches[1];

        if ($month === null || ! checkdate($month, $day, $year)) {
            return null;
        }

        return CarbonImmutable::create($year, $month, $day, 0, 0, 0, 'America/Lima');
    }

    private function isPositiveDecimal(string $value): bool
    {
        if (preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/D', $value) !== 1 || mb_strlen($value) > 64) {
            return false;
        }

        return trim($value, '0.') !== '';
    }
}
