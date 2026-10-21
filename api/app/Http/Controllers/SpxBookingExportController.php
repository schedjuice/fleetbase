<?php

namespace App\Http\Controllers;

use App\Services\SpxBookingCsvExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

/**
 * POST /int/v1/orders/spx-booking-export — SPX courier booking CSV download
 * for the console, scoped to the signed-in company's session.
 */
class SpxBookingExportController extends Controller
{
    public function __invoke(Request $request, SpxBookingCsvExport $export): Response|JsonResponse
    {
        $selections = $request->input('selections');

        if (!is_array($selections) || count($selections) === 0) {
            return $this->unprocessable('Provide at least one order to export.');
        }

        if (count($selections) > SpxBookingCsvExport::MAX_SELECTIONS) {
            return $this->unprocessable('Select at most ' . SpxBookingCsvExport::MAX_SELECTIONS . ' orders to export.');
        }

        try {
            $csv = $export->exportForSelections($selections);
        } catch (InvalidArgumentException $e) {
            return $this->unprocessable($e->getMessage());
        }

        return response($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="spx-booking-' . now()->format('Ymd') . '.csv"',
        ]);
    }

    private function unprocessable(string $message): JsonResponse
    {
        return response()->json(['message' => $message], 422);
    }
}
