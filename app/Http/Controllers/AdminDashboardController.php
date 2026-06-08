<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\InventoryTransaction;
use App\Models\LoginAttempt;
use App\Models\PosOrder;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\User;
use App\Services\PropertyFinancialSummaryService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AdminDashboardController extends Controller
{
    public function financialSummary(Request $request, PropertyFinancialSummaryService $summary)
    {
        $user = auth()->user();
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);

        return response()->json(
            $summary->summarize($validated['from'], $validated['to'], $user),
        );
    }

    /**
     * Administration hub snapshot for the portal dashboard.
     */
    public function adminSummary()
    {
        $user = auth()->user();
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        if (! $user->hasRole('Admin') && ! $user->can('manage-users')) {
            abort(403, 'Unauthorized action.');
        }

        $teamMembers = (int) User::query()->count();
        $rolesCount = (int) Role::query()->count();
        $permissionsCount = (int) Permission::query()->where('guard_name', '=', 'web')->count();
        $roomCategories = (int) RoomType::query()->count();

        $receipt = Setting::getReceiptDefaults();
        $invoiceProfile = Setting::getInvoiceProfile();
        $propertyName = trim((string) ($receipt['company_name'] ?? ''));
        $invoiceName = trim((string) ($invoiceProfile['invoice_company_name'] ?? ''));
        $gstinConfigured = trim((string) ($receipt['gstin'] ?? '')) !== '';
        $invoiceConfigured = $invoiceName !== '';
        $checkInConfigured = trim((string) Setting::get('standard_check_in_time', '')) !== '';
        $settingsConfigured = $propertyName !== '' || $invoiceName !== '';

        $activeUsers = $this->countActivePortalUsers();
        $recentActivity = $this->recentUserSignIns();
        $failedLogins24h = $this->failedLoginCount24h();
        $auditEvents24h = $this->auditEventCount24h();
        $lastBackupAt = $this->findLatestBackupAt();
        $twoFactor = $this->twoFactorSnapshot();
        $storage = $this->storageSnapshot();
        $emailQueue = $this->emailQueueSnapshot();

        $dbHealthy = true;
        try {
            DB::connection()->getPdo();
        } catch (\Throwable) {
            $dbHealthy = false;
        }

        $broadcastDriver = (string) config('broadcasting.default', 'null');
        $notificationsStatus = $broadcastDriver !== 'null' ? 'active' : 'local';

        return response()->json([
            'overview' => [
                'team_members' => $teamMembers,
                'roles' => $rolesCount,
                'permissions' => $permissionsCount,
                'settings_configured' => $settingsConfigured,
                'room_categories' => $roomCategories,
            ],
            'property' => [
                'name' => $propertyName !== '' ? $propertyName : ($invoiceName !== '' ? $invoiceName : null),
                'gstin_configured' => $gstinConfigured,
                'invoice_configured' => $invoiceConfigured,
                'room_categories_configured' => $roomCategories > 0,
                'check_in_configured' => $checkInConfigured,
            ],
            'access' => [
                'active_users' => $activeUsers,
                'roles' => $rolesCount,
                'permissions' => $permissionsCount,
                'recent_activity' => $recentActivity,
            ],
            'security' => [
                'two_factor' => $twoFactor,
                'failed_logins' => [
                    'count_24h' => $failedLogins24h,
                    'status' => $failedLogins24h > 0 ? 'warning' : 'healthy',
                    'label' => $failedLogins24h === 1
                        ? '1 failed attempt (24h)'
                        : "{$failedLogins24h} failed attempts (24h)",
                ],
                'last_backup' => $this->backupSnapshot($lastBackupAt),
                'audit_log' => [
                    'count_24h' => $auditEvents24h,
                    'status' => $auditEvents24h > 0 ? 'active' : 'healthy',
                    'label' => $auditEvents24h === 1
                        ? '1 audit event (24h)'
                        : "{$auditEvents24h} audit events (24h)",
                ],
            ],
            'system_health' => [
                'database' => [
                    'status' => $dbHealthy ? 'healthy' : 'error',
                    'label' => $dbHealthy ? 'Connected' : 'Unavailable',
                ],
                'storage' => $storage,
                'email_queue' => $emailQueue,
                'notifications' => [
                    'driver' => $broadcastDriver,
                    'status' => $notificationsStatus,
                    'label' => $notificationsStatus === 'active' ? 'Broadcasting on' : 'In-app only',
                ],
            ],
        ]);
    }

    private function countActivePortalUsers(): int
    {
        $since = now()->subDays(30);

        return (int) DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where(function ($query) use ($since) {
                $query->where('last_used_at', '>=', $since)
                    ->orWhere('created_at', '>=', $since);
            })
            ->distinct()
            ->count('tokenable_id');
    }

    /**
     * @return list<array{id: int, name: string, email: string, roles: list<string>, action: string, at: string}>
     */
    private function recentUserSignIns(): array
    {
        $tokenRows = DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->orderByRaw('COALESCE(last_used_at, created_at) DESC')
            ->limit(50)
            ->get(['tokenable_id', 'last_used_at', 'created_at']);

        $seenUserIds = [];
        $entries = [];

        foreach ($tokenRows as $row) {
            $userId = (int) $row->tokenable_id;
            if (isset($seenUserIds[$userId])) {
                continue;
            }
            $seenUserIds[$userId] = true;

            $at = $row->last_used_at ?? $row->created_at;
            if (! $at) {
                continue;
            }

            $entries[] = [
                'user_id' => $userId,
                'action' => $row->last_used_at ? 'Last sign-in' : 'Signed in',
                'at' => Carbon::parse($at)->toIso8601String(),
            ];

            if (count($entries) >= 5) {
                break;
            }
        }

        if ($entries === []) {
            return [];
        }

        $users = User::query()
            ->with('roles:id,name')
            ->whereIn('id', array_column($entries, 'user_id'))
            ->get(['id', 'name', 'email'])
            ->keyBy('id');

        return collect($entries)
            ->map(function (array $entry) use ($users) {
                /** @var User|null $user */
                $user = $users->get($entry['user_id']);
                if (! $user) {
                    return null;
                }

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->roles->pluck('name')->values()->all(),
                    'action' => $entry['action'],
                    'at' => $entry['at'],
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function failedLoginCount24h(): int
    {
        if (! Schema::hasTable('login_attempts')) {
            return 0;
        }

        return (int) LoginAttempt::query()
            ->where('successful', false)
            ->where('created_at', '>=', now()->subDay())
            ->count();
    }

    private function auditEventCount24h(): int
    {
        $since = now()->subDay();
        $count = 0;

        if (Schema::hasTable('login_attempts')) {
            $count += (int) LoginAttempt::query()
                ->where('successful', true)
                ->where('created_at', '>=', $since)
                ->count();
        }

        if (Schema::hasTable('inventory_transactions')) {
            $count += (int) InventoryTransaction::query()
                ->where('created_at', '>=', $since)
                ->count();
        }

        if (Schema::hasTable('pos_orders')) {
            $count += (int) PosOrder::query()
                ->where('updated_at', '>=', $since)
                ->count();
        }

        if (Schema::hasTable('bookings')) {
            $count += (int) Booking::query()
                ->where('updated_at', '>=', $since)
                ->count();
        }

        return $count;
    }

    private function findLatestBackupAt(): ?Carbon
    {
        $stored = trim((string) Setting::get('last_backup_at', ''));
        if ($stored !== '') {
            try {
                return Carbon::parse($stored);
            } catch (\Throwable) {
                // Fall through to filesystem scan.
            }
        }

        $latestTimestamp = null;
        $directories = [
            storage_path('app/backups'),
            storage_path('backups'),
            database_path('backups'),
        ];

        foreach ($directories as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            foreach (glob($directory . '/*') ?: [] as $file) {
                if (! is_file($file)) {
                    continue;
                }

                $mtime = @filemtime($file);
                if ($mtime && ($latestTimestamp === null || $mtime > $latestTimestamp)) {
                    $latestTimestamp = $mtime;
                }
            }
        }

        return $latestTimestamp ? Carbon::createFromTimestamp($latestTimestamp) : null;
    }

    /**
     * @return array{at: ?string, status: string, label: string}
     */
    private function backupSnapshot(?Carbon $lastBackupAt): array
    {
        if (! $lastBackupAt) {
            return [
                'at' => null,
                'status' => 'warning',
                'label' => 'No backups found',
            ];
        }

        $hoursAgo = $lastBackupAt->diffInHours(now());
        $status = $hoursAgo <= 48 ? 'healthy' : 'warning';

        return [
            'at' => $lastBackupAt->toIso8601String(),
            'status' => $status,
            'label' => 'Last backup ' . $lastBackupAt->diffForHumans(),
        ];
    }

    /**
     * @return array{available: bool, enabled_users?: int, status: string, label: string}|null
     */
    private function twoFactorSnapshot(): ?array
    {
        if (! Schema::hasColumn('users', 'two_factor_secret')) {
            return null;
        }

        $enabledUsers = (int) User::query()
            ->whereNotNull('two_factor_secret')
            ->where('two_factor_secret', '!=', '')
            ->count();

        return [
            'available' => true,
            'enabled_users' => $enabledUsers,
            'status' => $enabledUsers > 0 ? 'healthy' : 'warning',
            'label' => $enabledUsers === 1
                ? '1 user with 2FA'
                : "{$enabledUsers} users with 2FA",
        ];
    }

    /**
     * @return array{used_bytes: int, used_pct: ?int, status: string, label: string}
     */
    private function storageSnapshot(): array
    {
        $usedBytes = $this->directorySizeBytes(storage_path('app'));
        $usedMb = (int) round($usedBytes / 1024 / 1024);

        $diskPath = storage_path();
        $diskTotal = @disk_total_space($diskPath) ?: 0;
        $diskFree = @disk_free_space($diskPath) ?: 0;
        $diskUsedPct = $diskTotal > 0
            ? (int) round((($diskTotal - $diskFree) / $diskTotal) * 100)
            : null;

        $status = 'healthy';
        if ($diskUsedPct !== null && $diskUsedPct >= 90) {
            $status = 'warning';
        } elseif ($usedMb >= 1024) {
            $status = 'warning';
        }

        $label = $usedMb >= 1024
            ? round($usedMb / 1024, 1) . ' GB app storage'
            : "{$usedMb} MB app storage";

        if ($diskUsedPct !== null) {
            $label .= " · {$diskUsedPct}% disk";
        }

        return [
            'used_bytes' => $usedBytes,
            'used_pct' => $diskUsedPct,
            'status' => $status,
            'label' => $label,
        ];
    }

    /**
     * @return array{driver: string, status: string, label: string, pending_jobs?: int, failed_jobs?: int}
     */
    private function emailQueueSnapshot(): array
    {
        $mailDriver = (string) config('mail.default', 'smtp');
        $pendingJobs = Schema::hasTable('jobs') ? (int) DB::table('jobs')->count() : 0;
        $failedJobs = Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : 0;

        if ($pendingJobs > 0) {
            return [
                'driver' => $mailDriver,
                'status' => 'warning',
                'label' => "{$pendingJobs} queued job" . ($pendingJobs === 1 ? '' : 's'),
                'pending_jobs' => $pendingJobs,
                'failed_jobs' => $failedJobs,
            ];
        }

        if ($failedJobs > 0) {
            return [
                'driver' => $mailDriver,
                'status' => 'warning',
                'label' => "{$failedJobs} failed job" . ($failedJobs === 1 ? '' : 's'),
                'pending_jobs' => $pendingJobs,
                'failed_jobs' => $failedJobs,
            ];
        }

        if (in_array($mailDriver, ['sync', 'log'], true)) {
            return [
                'driver' => $mailDriver,
                'status' => 'ready',
                'label' => 'Direct send (' . $mailDriver . ')',
                'pending_jobs' => 0,
                'failed_jobs' => 0,
            ];
        }

        return [
            'driver' => $mailDriver,
            'status' => 'healthy',
            'label' => 'Queue empty · ' . ucfirst($mailDriver),
            'pending_jobs' => 0,
            'failed_jobs' => 0,
        ];
    }

    private function directorySizeBytes(string $path): int
    {
        if (! is_dir($path)) {
            return 0;
        }

        $size = 0;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
            }
        } catch (\Throwable) {
            return 0;
        }

        return $size;
    }
}
