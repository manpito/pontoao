<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Limites legais do trabalho extraordinário — Lei n.º 12/23 (LGT), art. 185.º
 * a) 2 horas por dia normal de trabalho; b) 40 horas por mês; c) 200 horas anuais.
 * Os fundamentos a) e d) do n.º 2 do art. 184.º não estão sujeitos a limites (art. 185.º, n.º 2).
 */
final class LimitesHorasExtra
{
    public const FUNDAMENTOS = ['a', 'b', 'c', 'd', 'e', 'f', 'g'];
    public const SEM_LIMITE = ['a', 'd'];
    public const MAX_DIA_NORMAL = 120;
    public const MAX_MES = 2400;
    public const MAX_ANO = 12000;

    /**
     * Devolve null se o pedido está dentro dos limites, ou a mensagem de erro.
     */
    public static function verificar(string $fundamento, int $minutos, bool $diaNormal, int $minutosMes, int $minutosAno): ?string
    {
        if (in_array($fundamento, self::SEM_LIMITE, true)) {
            return null;
        }
        if ($diaNormal && $minutos > self::MAX_DIA_NORMAL) {
            return 'Excede o limite de 2 horas por dia normal de trabalho (art. 185.º, n.º 1, a) da Lei n.º 12/23).';
        }
        if ($minutosMes + $minutos > self::MAX_MES) {
            return 'Excede o limite de 40 horas por mês (art. 185.º, n.º 1, b) da Lei n.º 12/23).';
        }
        if ($minutosAno + $minutos > self::MAX_ANO) {
            return 'Excede o limite de 200 horas anuais (art. 185.º, n.º 1, c) da Lei n.º 12/23).';
        }
        return null;
    }

    /**
     * Minutos entre duas horas HH:MM. Se o fim for anterior ou igual ao início, considera que passa a meia-noite.
     */
    public static function minutosEntre(string $inicio, string $fim): int
    {
        [$hi, $mi] = array_map('intval', explode(':', $inicio));
        [$hf, $mf] = array_map('intval', explode(':', $fim));
        $a = $hi * 60 + $mi;
        $b = $hf * 60 + $mf;
        if ($b <= $a) {
            $b += 1440;
        }
        return $b - $a;
    }
}
