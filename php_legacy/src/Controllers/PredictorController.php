<?php
declare(strict_types=1);

/**
 * PredictorController
 *
 * Routes:
 *   GET  /api.php?action=predictor_options
 *   POST /api.php?action=predict_by_rank        { rank, seat_type, gender, min_year? }
 *   POST /api.php?action=predict_for_preference { rank, iit_id, branch_id, seat_type, gender }
 */
final class PredictorController
{
    public function __construct(private PredictorService $service) {}

    public function options(): array
    {
        return $this->service->getDropdownOptions();
    }

    public function predictByRank(array $payload): array
    {
        $rank     = (int) ($payload['rank'] ?? 0);
        $seat     = (string) ($payload['seat_type'] ?? 'OPEN');
        $gender   = (string) ($payload['gender'] ?? 'Gender-Neutral');
        $minYear  = isset($payload['min_year']) ? (int) $payload['min_year'] : null;

        if ($rank < 1 || $rank > 1_500_000) {
            throw new InvalidArgumentException('Rank must be between 1 and 1,500,000');
        }

        return $this->service->predictByRank($rank, $seat, $gender, $minYear);
    }

    public function predictForPreference(array $payload): array
    {
        $rank     = (int) ($payload['rank'] ?? 0);
        $iitId    = (int) ($payload['iit_id'] ?? 0);
        $branchId = (int) ($payload['branch_id'] ?? 0);
        $seat     = (string) ($payload['seat_type'] ?? 'OPEN');
        $gender   = (string) ($payload['gender'] ?? 'Gender-Neutral');

        if ($rank < 1 || $rank > 1_500_000) {
            throw new InvalidArgumentException('Rank must be between 1 and 1,500,000');
        }
        if ($iitId < 1 || $branchId < 1) {
            throw new InvalidArgumentException('IIT and branch must be selected');
        }

        return $this->service->predictForPreference($rank, $iitId, $branchId, $seat, $gender);
    }
}
