<?php

namespace GlpiPlugin\Contractlifecycle;

/**
 * Utilitários de URL, formatação de horas/valores e renderização Twig inline.
 */
class Helpers
{
    public const DOMAIN = 'contractlifecycle';

    public static function url(string $path = ''): string
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . '/plugins/contractlifecycle/' . ltrim($path, '/');
    }

    /** Caminho relativo ao root_doc (para menus). */
    public static function path(string $path = ''): string
    {
        return '/plugins/contractlifecycle/' . ltrim($path, '/');
    }

    /** 5400 → "1h30"; negativo → "-1h30". */
    public static function hours(int $seconds, bool $signed = false): string
    {
        $sign = $seconds < 0 ? '-' : ($signed && $seconds > 0 ? '+' : '');
        $seconds = abs($seconds);
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        return $sign . $h . 'h' . ($m ? sprintf('%02d', $m) : '');
    }

    public static function money(float $value): string
    {
        return 'R$ ' . number_format($value, 2, ',', '.');
    }

    public static function date(?string $date): string
    {
        if (empty($date)) {
            return '—';
        }
        return \Html::convDate(substr($date, 0, 10));
    }

    public static function now(): string
    {
        return $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
    }

    public static function today(): string
    {
        return substr(self::now(), 0, 10);
    }

    public static function render(string $template, array $params = []): string
    {
        return \Glpi\Application\View\TemplateRenderer::getInstance()->render('@contractlifecycle/' . $template, $params);
    }
}
