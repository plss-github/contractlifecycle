<?php

namespace GlpiPlugin\Contractlifecycle;

use Calendar;
use DateInterval;
use DateTimeImmutable;
use Entity;

/**
 * Calcula quanto um apontamento debita.
 *
 * Faixas: comercial (dias úteis dentro do horário), fora do horário (dias úteis fora dele) e
 * fim de semana/feriado. Cada faixa tem um mínimo (em minutos), usado conforme rounding_mode:
 *  - none:    debitado = tempo real;
 *  - minimum: debitado = max(tempo real, mínimo da faixa);
 *  - up:      debitado = tempo real arredondado para cima em blocos do mínimo da faixa (bloco de 15 min:
 *             0h12 → 0h15, 1h13 → 1h15, 0h16 → 0h30); apontamento de 0 min debita um bloco.
 *
 * Apontamento que cruza faixas:
 *  - boundary_rule escolhe a faixa (início | fim | comercial | não comercial);
 *  - com rounding ligado vale a faixa de maior cobrança (valor em R$ se o contrato cobra em valor, senão horas);
 *    empate segue boundary_rule.
 */
class Calculator
{
    public const BAND_BUSINESS = 'business';
    public const BAND_OFFHOURS = 'offhours';
    public const BAND_WEEKEND  = 'weekend';

    private const SAMPLE_MINUTES = 5;
    private const MAX_SAMPLES = 4032; // 14 dias em passos de 5 min

    private ?Calendar $calendar = null;
    private array $holidayCache = [];

    public function __construct(private array $settings, int $entities_id = 0)
    {
        if (!empty($settings['use_holidays'])) {
            $calendars_id = (int) ($settings['calendars_id'] ?? 0);
            if ($calendars_id <= 0) {
                $calendars_id = (int) Entity::getUsedConfig('calendars_strategy', $entities_id, 'calendars_id', 0);
            }
            $calendar = new Calendar();
            if ($calendars_id > 0 && $calendar->getFromDB($calendars_id)) {
                $this->calendar = $calendar;
            }
        }
    }

    public static function bandLabels(): array
    {
        return [
            self::BAND_BUSINESS => __('Horário comercial', 'contractlifecycle'),
            self::BAND_OFFHOURS => __('Fora do horário', 'contractlifecycle'),
            self::BAND_WEEKEND  => __('Fim de semana/feriado', 'contractlifecycle'),
        ];
    }

    public function band(DateTimeImmutable $at): string
    {
        $days = array_map('intval', (array) $this->settings['business_days']);
        if (!in_array((int) $at->format('w'), $days, true) || $this->isHoliday($at->format('Y-m-d'))) {
            return self::BAND_WEEKEND;
        }
        $time = $at->format('H:i');
        return ($time >= $this->settings['business_start'] && $time < $this->settings['business_end'])
            ? self::BAND_BUSINESS
            : self::BAND_OFFHOURS;
    }

    public function isHolidayDate(string $date): bool
    {
        return $this->isHoliday($date);
    }

    private function isHoliday(string $date): bool
    {
        if ($this->calendar === null) {
            return false;
        }
        return $this->holidayCache[$date] ??= (bool) $this->calendar->isHoliday($date);
    }

    public function minimumSeconds(string $band): int
    {
        $minutes = match ($band) {
            self::BAND_OFFHOURS => $this->settings['min_offhours'],
            self::BAND_WEEKEND  => $this->settings['min_weekend'],
            default             => $this->settings['min_business'],
        };
        return max(0, (int) $minutes) * 60;
    }

    public const ROUNDING_NONE    = 'none';
    public const ROUNDING_MINIMUM = 'minimum';
    public const ROUNDING_UP      = 'up';

    /** Tempo debitado na faixa, conforme o modo de arredondamento. */
    public function debitedSeconds(string $band, int $seconds): int
    {
        $seconds = max(0, $seconds);
        $block = $this->minimumSeconds($band);
        return match ($this->settings['rounding_mode'] ?? self::ROUNDING_MINIMUM) {
            self::ROUNDING_NONE => $seconds,
            self::ROUNDING_UP   => $block > 0 ? max(1, (int) ceil($seconds / $block)) * $block : $seconds,
            default             => max($seconds, $block),
        };
    }

    /** Faixas tocadas pelo intervalo, na ordem em que aparecem. */
    public function bandsTouched(DateTimeImmutable $start, int $seconds): array
    {
        $bands = [$this->band($start)];
        if ($seconds <= 0) {
            return $bands;
        }
        $end = $start->modify('+' . ($seconds - 1) . ' seconds');
        $step = new DateInterval('PT' . self::SAMPLE_MINUTES . 'M');
        $cursor = $start;
        for ($i = 0; $i < self::MAX_SAMPLES && $cursor < $end; $i++) {
            $cursor = $cursor->add($step);
            $probe = $cursor > $end ? $end : $cursor;
            $band = $this->band($probe);
            if (!in_array($band, $bands, true)) {
                $bands[] = $band;
            }
        }
        $last = $this->band($end);
        if (!in_array($last, $bands, true)) {
            $bands[] = $last;
        }
        return $bands;
    }

    /**
     * @param string     $start   início do apontamento (Y-m-d H:i:s)
     * @param int        $seconds tempo real
     * @param array|null $rate    linha da tabela Per-Event (só quando o contrato cobra em valor)
     */
    public function compute(string $start, int $seconds, ?array $rate = null): array
    {
        $startDt = new DateTimeImmutable($start);
        $seconds = max(0, $seconds);
        $touched = $this->bandsTouched($startDt, $seconds);
        $startBand = $touched[0];
        $endBand = $seconds > 0 ? $this->band($startDt->modify('+' . ($seconds - 1) . ' seconds')) : $startBand;

        $byRule = $this->pickByRule($touched, $startBand, $endBand);
        $band = $byRule;

        if (!empty($this->settings['rounding']) && count($touched) > 1) {
            $best = null;
            $bestValue = -1.0;
            foreach ($touched as $candidate) {
                $value = $this->valueOf($candidate, $seconds, $rate);
                if ($value > $bestValue + 1e-9) {
                    $best = $candidate;
                    $bestValue = $value;
                }
            }
            // empate: se a faixa da regra está entre as de maior valor, ela vence
            if (abs($this->valueOf($byRule, $seconds, $rate) - $bestValue) < 1e-9) {
                $best = $byRule;
            }
            $band = $best;
        }

        $debited = $this->debitedSeconds($band, $seconds);
        $unit = $rate !== null ? (float) $rate['hourly_value'] : 0.0;
        $multiplier = $rate !== null ? Rate::multiplierFor($rate, $band) : 1.0;

        return [
            'band'       => $band,
            'bands'      => $touched,
            'real'       => $seconds,
            'debited'    => $debited,
            'unit_value' => $unit,
            'multiplier' => $multiplier,
            'amount'     => round($debited / 3600 * $unit * $multiplier, 2),
        ];
    }

    private function pickByRule(array $touched, string $startBand, string $endBand): string
    {
        if (count($touched) === 1) {
            return $startBand;
        }
        return match ($this->settings['boundary_rule'] ?? 'start') {
            'end'      => $endBand,
            'business' => in_array(self::BAND_BUSINESS, $touched, true) ? self::BAND_BUSINESS : $startBand,
            'nonbusiness' => $this->firstNonBusiness([$startBand, $endBand, ...$touched]) ?? $startBand,
            default    => $startBand,
        };
    }

    private function firstNonBusiness(array $bands): ?string
    {
        foreach ($bands as $band) {
            if ($band !== self::BAND_BUSINESS) {
                return $band;
            }
        }
        return null;
    }

    private function valueOf(string $band, int $seconds, ?array $rate): float
    {
        $debited = $this->debitedSeconds($band, $seconds);
        if ($rate === null || (float) $rate['hourly_value'] <= 0) {
            return (float) $debited;
        }
        return $debited / 3600 * (float) $rate['hourly_value'] * Rate::multiplierFor($rate, $band);
    }
}
