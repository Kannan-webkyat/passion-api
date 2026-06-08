<?php

namespace App\Services;

use App\Models\DailyRoomCleaning;
use App\Models\HousekeepingChecklistItem;
use App\Models\ServiceChecklistItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class HousekeepingChecklistService
{
    /**
     * @return array<int, array{key: string, label: string, required: bool, estimated_minutes: int|null, display_order: int, section: string|null}>
     */
    public function activeTemplateForCategory(string $category): array
    {
        return HousekeepingChecklistItem::query()
            ->where('category', '=', $category, 'and')
            ->where('is_active', '=', true, 'and')
            ->orderBy('display_order')
            ->orderBy('id')
            ->get()
            ->map(fn(HousekeepingChecklistItem $row) => $this->formatMasterRow($row))
            ->values()
            ->all();
    }

    /**
     * Legacy shape for catalog / daily board (`key` + `label`).
     *
     * @return array<int, array{key: string, label: string, required?: bool, estimated_minutes?: int|null}>
     */
    public function legacyChecklistForCategory(string $category): array
    {
        return array_map(function (array $row) {
            return [
                'key' => $row['key'],
                'label' => $row['label'],
                'required' => $row['required'],
                'estimated_minutes' => $row['estimated_minutes'],
            ];
        }, $this->activeTemplateForCategory($category));
    }

    /**
     * @return array<string, array<int, array{key: string, label: string, required: bool, estimated_minutes: int|null}>>
     */
    public function checkoutInspectionTemplateGrouped(): array
    {
        $rows = $this->activeTemplateForCategory(HousekeepingChecklistItem::CATEGORY_CHECKOUT_INSPECTION);
        $grouped = [
            'room_condition' => [],
            'linen_check' => [],
            'maintenance_flags' => [],
            'general' => [],
        ];

        foreach ($rows as $row) {
            $section = $row['section'] ?: 'general';
            if (! isset($grouped[$section])) {
                $grouped[$section] = [];
            }
            $grouped[$section][] = [
                'key' => $row['key'],
                'label' => $row['label'],
                'required' => $row['required'],
                'estimated_minutes' => $row['estimated_minutes'],
            ];
        }

        return $grouped;
    }

    public function ensureDailyCleaningSnapshot(DailyRoomCleaning $cleaning): void
    {
        $exists = ServiceChecklistItem::query()
            ->where('service_type', '=', ServiceChecklistItem::SERVICE_DAILY_ROOM_CLEANING, 'and')
            ->where('service_id', '=', $cleaning->id, 'and')
            ->exists();

        if ($exists) {
            return;
        }

        $template = $this->activeTemplateForCategory(HousekeepingChecklistItem::CATEGORY_DAILY_ROOM_CLEANING);
        if ($template === []) {
            return;
        }

        $saved = is_array($cleaning->checklist_done) ? $cleaning->checklist_done : [];

        foreach ($template as $item) {
            ServiceChecklistItem::create([
                'service_type' => ServiceChecklistItem::SERVICE_DAILY_ROOM_CLEANING,
                'service_id' => $cleaning->id,
                'task_key' => $item['key'],
                'task_name' => $item['label'],
                'section' => $item['section'],
                'display_order' => $item['display_order'],
                'required' => $item['required'],
                'completed' => (bool) ($saved[$item['key']] ?? false),
                'estimated_minutes' => $item['estimated_minutes'],
            ]);
        }
    }

    /**
     * @return Collection<int, ServiceChecklistItem>
     */
    public function dailyCleaningSnapshotItems(DailyRoomCleaning $cleaning): Collection
    {
        return ServiceChecklistItem::query()
            ->where('service_type', '=', ServiceChecklistItem::SERVICE_DAILY_ROOM_CLEANING, 'and')
            ->where('service_id', '=', $cleaning->id, 'and')
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<int, array{key: string, label: string, required: bool, estimated_minutes: int|null, done: bool}>
     */
    public function dailyCleaningChecklistForRecord(DailyRoomCleaning $cleaning): array
    {
        $snapshot = $this->dailyCleaningSnapshotItems($cleaning);
        if ($snapshot->isNotEmpty()) {
            return $snapshot->map(fn(ServiceChecklistItem $row) => [
                'key' => $row->task_key,
                'label' => $row->task_name,
                'required' => (bool) $row->required,
                'estimated_minutes' => $row->estimated_minutes,
                'done' => (bool) $row->completed,
            ])->values()->all();
        }

        $saved = is_array($cleaning->checklist_done) ? $cleaning->checklist_done : [];
        $template = $this->activeTemplateForCategory(HousekeepingChecklistItem::CATEGORY_DAILY_ROOM_CLEANING);

        return array_map(function (array $item) use ($saved) {
            return [
                'key' => $item['key'],
                'label' => $item['label'],
                'required' => $item['required'],
                'estimated_minutes' => $item['estimated_minutes'],
                'done' => (bool) ($saved[$item['key']] ?? false),
            ];
        }, $template);
    }

    /**
     * @param  array<string, mixed>|null  $raw
     */
    public function applyDailyCleaningCompletion(DailyRoomCleaning $cleaning, ?array $raw): void
    {
        if ($raw === null) {
            return;
        }

        $this->ensureDailyCleaningSnapshot($cleaning);
        $snapshot = $this->dailyCleaningSnapshotItems($cleaning);

        if ($snapshot->isEmpty()) {
            $cleaning->checklist_done = $this->sanitizeLegacyDailyDone($raw);

            return;
        }

        $allowed = $snapshot->pluck('task_key')->flip();
        foreach ($raw as $key => $value) {
            if (! is_string($key) || ! isset($allowed[$key])) {
                continue;
            }
            ServiceChecklistItem::query()
                ->where('service_type', '=', ServiceChecklistItem::SERVICE_DAILY_ROOM_CLEANING, 'and')
                ->where('service_id', '=', $cleaning->id, 'and')
                ->where('task_key', '=', $key, 'and')
                ->update(['completed' => (bool) $value]);
        }

        $cleaning->checklist_done = $this->mirrorSnapshotToLegacyMap($cleaning);
    }

    public function validateDailyCleaningRequiredComplete(DailyRoomCleaning $cleaning): ?string
    {
        $snapshot = $this->dailyCleaningSnapshotItems($cleaning);
        if ($snapshot->isEmpty()) {
            return null;
        }

        $required = $snapshot->where('required', true);
        if ($required->isEmpty()) {
            return null;
        }

        $done = $required->where('completed', true)->count();
        $total = $required->count();
        if ($done >= $total) {
            return null;
        }

        return "{$done} of {$total} required tasks completed. Finish all required checklist items before marking the room cleaned.";
    }

    /**
     * @return array<string, bool>
     */
    public function mirrorSnapshotToLegacyMap(DailyRoomCleaning $cleaning): array
    {
        $out = [];
        foreach ($this->dailyCleaningSnapshotItems($cleaning) as $row) {
            $out[$row->task_key] = (bool) $row->completed;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>|null  $raw
     * @return array<string, bool>
     */
    public function sanitizeLegacyDailyDone(?array $raw): array
    {
        if ($raw === null) {
            return [];
        }

        $allowed = array_flip(array_column(
            $this->activeTemplateForCategory(HousekeepingChecklistItem::CATEGORY_DAILY_ROOM_CLEANING),
            'key'
        ));
        $out = [];
        foreach ($raw as $k => $v) {
            if (! is_string($k) || ! isset($allowed[$k])) {
                continue;
            }
            $out[$k] = (bool) $v;
        }

        return $out;
    }

    public function slugTaskKey(string $taskName, string $category): string
    {
        $base = Str::slug($taskName, '_');
        if ($base === '') {
            $base = 'task';
        }

        $exists = HousekeepingChecklistItem::query()
            ->where('category', '=', $category, 'and')
            ->where('task_key', '=', $base, 'and')
            ->exists();

        if (! $exists) {
            return $base;
        }

        return $base . '_' . Str::lower(Str::random(4));
    }

    /**
     * @return array{key: string, label: string, required: bool, estimated_minutes: int|null, display_order: int, section: string|null}
     */
    private function formatMasterRow(HousekeepingChecklistItem $row): array
    {
        return [
            'key' => (string) $row->task_key,
            'label' => (string) $row->task_name,
            'required' => (bool) $row->required,
            'estimated_minutes' => $row->estimated_minutes !== null ? (int) $row->estimated_minutes : null,
            'display_order' => (int) $row->display_order,
            'section' => $row->section !== null ? (string) $row->section : null,
        ];
    }
}
