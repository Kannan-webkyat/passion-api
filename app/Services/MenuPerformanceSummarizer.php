<?php

namespace App\Services;

/**
 * Roll up menu performance variant rows (e.g. MCR 30ml / 60ml / 90ml) into parent SKU rows.
 * Spirits: "10 btl 1.5 peg" (1 peg = 60ml by default when variants exist).
 */
final class MenuPerformanceSummarizer
{
    public function __construct(
        private readonly float $pegMl = 60.0,
    ) {}

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @return \Illuminate\Support\Collection<int, object>
     */
    public function summarize($rows)
    {
        $combos = $rows->where('row_kind', 'combo')->values();
        $menuGroups = $rows->where('row_kind', 'menu_item')->groupBy('menu_item_id');

        $summarized = $menuGroups->map(function ($variantRows, $menuItemId) {
            $variantRows = $variantRows->values();
            $first = $variantRows->first();
            $isLiquor = (bool) ($first->is_liquor ?? false);
            $bottleMl = $this->resolveBottleMl($first);
            $singleRowNoVariant = $variantRows->count() <= 1
                && trim((string) ($first->variant_label ?? '')) === '';

            if ($singleRowNoVariant) {
                if ($isLiquor) {
                    return $this->withLiquorQtyDisplay($first, $variantRows, $bottleMl, (int) $menuItemId);
                }

                return $first;
            }

            $qtySold = $variantRows->sum(fn ($r) => (float) $r->qty_sold);
            $revenue = $variantRows->sum(fn ($r) => (float) $r->revenue);
            $linesSold = $variantRows->sum(fn ($r) => (int) $r->lines_sold);
            $billsCount = $variantRows->max(fn ($r) => (int) $r->bills_count);

            $qtyDisplay = null;
            $bottles = null;
            $pegs = null;

            if ($isLiquor) {
                [$bottles, $pegs] = $this->splitBottlesAndPegs($variantRows, $bottleMl);
                $qtyDisplay = $this->formatBottlePegDisplay($bottles, $pegs);
            }

            return (object) [
                'row_kind' => 'menu_item',
                'menu_item_id' => (int) $menuItemId,
                'combo_id' => null,
                'variant_id' => null,
                'name' => (string) ($first->name ?? ''),
                'variant_label' => null,
                'category_name' => (string) ($first->category_name ?? '—'),
                'qty_sold' => $qtySold,
                'qty_display' => $qtyDisplay,
                'bottles_sold' => $bottles,
                'pegs_sold' => $pegs,
                'revenue' => $revenue,
                'lines_sold' => $linesSold,
                'bills_count' => $billsCount,
                'is_liquor' => $isLiquor,
                'is_summarized' => true,
            ];
        })->values();

        return $summarized->concat($combos)->sortByDesc(fn ($r) => (float) $r->revenue)->values();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $variantRows
     * @return array{0: float, 1: float}
     */
    private function splitBottlesAndPegs($variantRows, float $bottleMl): array
    {
        $totalMl = 0.0;
        $rawBottleCount = 0.0;

        foreach ($variantRows as $row) {
            $qty = (float) ($row->qty_sold ?? 0);
            if ($qty <= 0) {
                continue;
            }

            $ml = (float) ($row->variant_ml ?? 0);
            $label = strtolower(trim((string) ($row->variant_label ?? '')));
            $cf = max(1.0, (float) ($row->conversion_factor ?? 1));
            $rowBottleMl = $this->resolveBottleMl($row);

            if ($this->isFullBottleSale($ml, $label, $cf, $rowBottleMl)) {
                if ($ml <= 1.0001 && $cf > 1.0001) {
                    $totalMl += $qty * $cf;
                } elseif ($ml >= 100) {
                    $totalMl += $qty * $ml;
                } else {
                    $totalMl += $qty * ($rowBottleMl >= 100 ? $rowBottleMl : $cf);
                }

                continue;
            }

            if ($ml > 0 && $ml < 100) {
                $totalMl += $qty * $ml;

                continue;
            }

            if ($ml >= 100) {
                $totalMl += $qty * $ml;

                continue;
            }

            if ($rowBottleMl >= 100) {
                $totalMl += $qty * $rowBottleMl;

                continue;
            }

            if ($cf > 1.0001) {
                $totalMl += $qty * $cf;

                continue;
            }

            $rawBottleCount += $qty;
        }

        if ($totalMl > 0 && $bottleMl >= 100) {
            return $this->splitTotalMl($totalMl, $bottleMl);
        }

        if ($rawBottleCount > 0) {
            return [$rawBottleCount, 0.0];
        }

        return [0.0, 0.0];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $variantRows
     */
    private function withLiquorQtyDisplay(object $first, $variantRows, float $bottleMl, int $menuItemId): object
    {
        [$bottles, $pegs] = $this->splitBottlesAndPegs($variantRows, $bottleMl);

        return (object) [
            'row_kind' => 'menu_item',
            'menu_item_id' => $menuItemId,
            'combo_id' => null,
            'variant_id' => $first->variant_id ?? null,
            'name' => (string) ($first->name ?? ''),
            'variant_label' => null,
            'category_name' => (string) ($first->category_name ?? '—'),
            'qty_sold' => (float) ($first->qty_sold ?? 0),
            'qty_display' => $this->formatBottlePegDisplay($bottles, $pegs),
            'bottles_sold' => $bottles,
            'pegs_sold' => $pegs,
            'revenue' => (float) ($first->revenue ?? 0),
            'lines_sold' => (int) ($first->lines_sold ?? 0),
            'bills_count' => (int) ($first->bills_count ?? 0),
            'is_liquor' => true,
            'is_summarized' => true,
        ];
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function splitTotalMl(float $qtyMl, float $bottleMl): array
    {
        if ($bottleMl <= 0.0001) {
            return [0.0, 0.0];
        }

        $bottles = floor($qtyMl / $bottleMl);
        $remMl = max(0.0, $qtyMl - ($bottles * $bottleMl));

        if ($this->pegMl <= 0.0001 || $remMl < 0.0001) {
            return [(float) $bottles, 0.0];
        }

        $halfPegMl = $this->pegMl / 2;
        $halfPegs = (int) round($remMl / $halfPegMl);
        $pegs = $halfPegs / 2;

        $maxHalfInBottle = (int) floor($bottleMl / $halfPegMl);
        if ($halfPegs >= $maxHalfInBottle && $maxHalfInBottle > 0) {
            $extraBottles = intdiv($halfPegs, $maxHalfInBottle);
            $halfPegs = $halfPegs % $maxHalfInBottle;
            $bottles += $extraBottles;
            $pegs = $halfPegs / 2;
        }

        return [(float) $bottles, round($pegs, 2)];
    }

    private function isFullBottleSale(float $ml, string $label, float $cf, float $bottleMl): bool
    {
        if (
            str_contains($label, 'full')
            || str_contains($label, 'bottle')
            || str_contains($label, 'btl')
            || str_contains($label, 'bottile')
        ) {
            return true;
        }

        if ($ml >= 100) {
            return true;
        }

        if ($ml <= 1.0001 && $cf > 1.0001 && $bottleMl >= 100) {
            return true;
        }

        if ($bottleMl >= 100 && abs($ml - $bottleMl) < 1) {
            return true;
        }

        return false;
    }

    private function resolveBottleMl(object $row): float
    {
        $cf = (float) ($row->conversion_factor ?? 0);
        if ($cf >= 100) {
            return $cf;
        }

        $name = (string) ($row->name ?? '');
        if (preg_match('/(1000|750|650|500|375|330)/', $name, $m)) {
            return (float) $m[1];
        }

        return 0.0;
    }

    private function formatBottlePegDisplay(float $bottles, float $pegs): string
    {
        $parts = [];
        if ($bottles > 0.0001) {
            $parts[] = $this->formatQty($bottles).' btl';
        }
        if ($pegs > 0.0001) {
            $parts[] = $this->formatQty($pegs).' peg';
        }

        if ($parts === []) {
            return '0';
        }

        return implode(' ', $parts);
    }

    private function formatQty(float $n): string
    {
        if (abs($n - round($n)) < 0.001) {
            return (string) (int) round($n);
        }

        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }
}
