<?php
declare(strict_types=1);

namespace Wichtelberg;

final class Draw
{
    /**
     * Auslosung als ein einziger Kreis: zufällige Permutation (Fisher-Yates mit random_int,
     * kryptografisch sicher); jeder beschenkt den Nächsten, der Letzte den Ersten.
     * Garantiert: niemand beschenkt sich selbst; ab 3 Personen keine gegenseitigen Paare.
     *
     * @param string[] $ids
     * @return array<string, string> Schenkender => Beschenkter
     */
    public static function cycle(array $ids): array
    {
        $order = array_values($ids);
        $n = count($order);
        if ($n < 2) {
            throw new \InvalidArgumentException('Für eine Auslosung sind mindestens 2 Teilnehmer nötig.');
        }
        for ($i = $n - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
        }
        $result = [];
        foreach ($order as $i => $id) {
            $result[$id] = $order[($i + 1) % $n];
        }
        return $result;
    }
}
