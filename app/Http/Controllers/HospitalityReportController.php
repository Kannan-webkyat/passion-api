<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesSpatiePermissions;
use App\Services\HospitalityReportService;
use Illuminate\Http\Request;

class HospitalityReportController extends Controller
{
    use AuthorizesSpatiePermissions;

    public function __construct(private HospitalityReportService $reports) {}

    public function roomsPerformance(Request $request)
    {
        $this->authorizePermissions(['report-rooms-performance', 'reservation-view']);
        [$from, $to] = $this->validatedRange($request);

        return response()->json($this->reports->roomsPerformance($from, $to));
    }

    public function frontOfficeDailyFlash(Request $request)
    {
        $this->authorizePermissions(['report-front-office-flash', 'reservation-view']);
        $validated = $request->validate([
            'date' => 'required|date',
        ]);

        return response()->json($this->reports->frontOfficeDailyFlash($validated['date']));
    }

    public function housekeepingProductivity(Request $request)
    {
        $this->authorizePermissions([
            'report-housekeeping-productivity',
            'housekeeping-daily-room-cleaning',
            'housekeeping-dirty-rooms',
        ]);
        [$from, $to] = $this->validatedRange($request);

        return response()->json($this->reports->housekeepingProductivity($from, $to));
    }

    public function cleaningScheduleAdherence(Request $request)
    {
        $this->authorizePermissions([
            'report-cleaning-adherence',
            'housekeeping-cleaning-availability',
            'housekeeping-daily-room-cleaning',
        ]);
        [$from, $to] = $this->validatedRange($request);

        return response()->json($this->reports->cleaningScheduleAdherence($from, $to));
    }

    public function channelSourceMix(Request $request)
    {
        $this->authorizePermissions(['report-channel-source-mix', 'reservation-view']);
        [$from, $to] = $this->validatedRange($request);

        return response()->json($this->reports->channelSourceMix($from, $to));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function validatedRange(Request $request): array
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);

        return [$validated['from'], $validated['to']];
    }
}
