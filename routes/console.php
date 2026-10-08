<?php

use App\Http\Controllers\PosController;
use App\Models\PosOrder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('pos:recalculate-tax-splits {--chunk=200}', function () {
    $chunk = max(1, (int) $this->option('chunk'));
    $ctrl = app(PosController::class);
    $n = 0;
    PosOrder::query()->orderBy('id')->chunkById($chunk, function ($orders) use ($ctrl, &$n) {
        foreach ($orders as $order) {
            $o = PosOrder::query()
                ->with([
                    'items' => fn ($q) => $q->where('status', 'active'),
                    'items.menuItem.tax',
                    'items.combo.menuItems.tax',
                ])
                ->find($order->id);
            if ($o) {
                $ctrl->maintenanceRecalculateOrderTotals($o);
                $n++;
            }
        }
    });
    $this->info("Recalculated {$n} orders.");
})->purpose('Backfill CGST/SGST/IGST/VAT columns on pos_orders from line items + tax master');

Artisan::command('guest-identities:move-to-private {--from=public} {--dry-run}', function () {
    $service = app(\App\Services\GuestIdentityImageService::class);
    $from = (string) $this->option('from');
    $to = $service->disk();
    if ($from === $to) {
        $this->error("Source and target disk are both '{$to}'. Set GUEST_IDENTITY_DISK to a private disk first.");

        return 1;
    }

    $directory = trim((string) config('guest_identity.directory', 'identities'), '/');
    $source = \Illuminate\Support\Facades\Storage::disk($from);
    $target = \Illuminate\Support\Facades\Storage::disk($to);
    $dry = (bool) $this->option('dry-run');
    $moved = 0;
    $skipped = 0;

    foreach ($source->allFiles($directory) as $path) {
        if ($target->exists($path)) {
            $skipped++;
        } elseif (! $dry) {
            $target->put($path, $source->get($path));
            $moved++;
        } else {
            $moved++;
        }
        if (! $dry && $target->exists($path)) {
            $source->delete($path);
        }
    }

    $unmanaged = 0;
    \App\Models\Booking::query()->whereNotNull('guest_identities')->select(['id', 'guest_identities'])
        ->chunkById(500, function ($bookings) use ($service, &$unmanaged) {
            foreach ($bookings as $b) {
                foreach ((array) $b->guest_identities as $p) {
                    if (is_string($p) && $p !== '' && ! $service->isManagedPath($p)) {
                        $unmanaged++;
                        $this->warn("Booking #{$b->id}: unrecognised identity path '{$p}' (will not be served).");
                    }
                }
            }
        });

    $verb = $dry ? 'Would move' : 'Moved';
    $this->info("{$verb} {$moved} file(s) from '{$from}' to '{$to}'; {$skipped} already present; {$unmanaged} unrecognised booking path(s).");

    return 0;
})->purpose('Move stored guest ID documents from the public disk to the private guest identity disk');
