<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesHousekeepingPermissions;
use App\Http\Controllers\Concerns\AuthorizesSpatiePermissions;
use App\Models\HousekeepingChecklistItem;
use App\Services\HousekeepingChecklistService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class HousekeepingChecklistController extends Controller
{
    use AuthorizesHousekeepingPermissions;
    use AuthorizesSpatiePermissions;

    public function __construct(
        private readonly HousekeepingChecklistService $checklists,
    ) {}

    public function meta()
    {
        $this->authorizePermissions(['housekeeping-checklist-master']);

        return response()->json([
            'categories' => collect(HousekeepingChecklistItem::categoryLabels())
                ->map(fn($label, $value) => ['value' => $value, 'label' => $label])
                ->values(),
        ]);
    }

    public function index(Request $request)
    {
        $this->authorizePermissions(['housekeeping-checklist-master']);

        $validated = $request->validate([
            'category' => ['nullable', Rule::in(array_keys(HousekeepingChecklistItem::categoryLabels()))],
            'search' => 'nullable|string|max:120',
            'include_inactive' => 'nullable|boolean',
        ]);

        $rows = HousekeepingChecklistItem::query()
            ->when(
                $validated['category'] ?? null,
                fn($q, $cat) => $q->where('category', '=', $cat, 'and'),
            )
            ->when(
                ! ($validated['include_inactive'] ?? false),
                fn($q) => $q->where('is_active', '=', true, 'and'),
            )
            ->when(
                filled($validated['search'] ?? null),
                function ($q) use ($validated) {
                    $term = '%' . trim((string) $validated['search']) . '%';
                    $q->where(function ($inner) use ($term) {
                        $inner->where('task_name', 'like', $term, 'and')
                            ->orWhere('task_key', 'like', $term, 'and');
                    });
                },
            )
            ->orderBy('category')
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        return response()->json($rows);
    }

    public function store(Request $request)
    {
        $this->authorizePermissions(['housekeeping-checklist-master']);

        $validated = $this->validatePayload($request);
        if (empty($validated['task_key'])) {
            $validated['task_key'] = $this->checklists->slugTaskKey(
                $validated['task_name'],
                $validated['category'],
            );
        }

        if (! isset($validated['display_order'])) {
            $max = (int) HousekeepingChecklistItem::query()
                ->where('category', '=', $validated['category'], 'and')
                ->max('display_order');
            $validated['display_order'] = $max + 1;
        }

        $row = HousekeepingChecklistItem::create($validated);

        return response()->json($row, 201);
    }

    public function update(Request $request, HousekeepingChecklistItem $housekeepingChecklistItem)
    {
        $this->authorizePermissions(['housekeeping-checklist-master']);

        $validated = $this->validatePayload($request, $housekeepingChecklistItem->id);
        $housekeepingChecklistItem->update($validated);

        return response()->json($housekeepingChecklistItem->fresh());
    }

    public function destroy(HousekeepingChecklistItem $housekeepingChecklistItem)
    {
        $this->authorizePermissions(['housekeeping-checklist-master']);
        $housekeepingChecklistItem->delete();

        return response()->json(['message' => 'Checklist item deleted.']);
    }

    public function reorder(Request $request)
    {
        $this->authorizePermissions(['housekeeping-checklist-master']);

        $validated = $request->validate([
            'category' => ['required', Rule::in(array_keys(HousekeepingChecklistItem::categoryLabels()))],
            'ordered_ids' => 'required|array|min:1',
            'ordered_ids.*' => 'integer|exists:housekeeping_checklist_items,id',
        ]);

        $ids = array_map('intval', $validated['ordered_ids']);
        $rows = HousekeepingChecklistItem::query()
            ->whereIn('id', $ids, 'and', false)
            ->where('category', '=', $validated['category'], 'and')
            ->get()
            ->keyBy('id');

        if ($rows->count() !== count($ids)) {
            return response()->json(['message' => 'Reorder list must only include items from the selected category.'], 422);
        }

        DB::transaction(function () use ($ids) {
            foreach ($ids as $index => $id) {
                HousekeepingChecklistItem::whereKey($id)->update(['display_order' => $index + 1]);
            }
        });

        return response()->json(['message' => 'Order updated.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, ?int $ignoreId = null): array
    {
        $categories = array_keys(HousekeepingChecklistItem::categoryLabels());

        return $request->validate([
            'task_name' => 'sometimes|required|string|max:255',
            'task_key' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('housekeeping_checklist_items', 'task_key')
                    ->where(fn($q) => $q->where('category', $request->input('category')))
                    ->ignore($ignoreId),
            ],
            'category' => ['sometimes', 'required', Rule::in($categories)],
            'section' => 'nullable|string|max:64',
            'display_order' => 'nullable|integer|min:0|max:9999',
            'required' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'estimated_minutes' => 'nullable|integer|min:1|max:600',
        ]);
    }
}
