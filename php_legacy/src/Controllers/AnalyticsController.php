<?php
declare(strict_types=1);

/**
 * AnalyticsController
 *
 * Thin controller: routes an action name to the matching model method.
 * Everything else (JSON encoding, error handling) lives in the API front-controller.
 */
final class AnalyticsController
{
    public function __construct(private AllotmentModel $model) {}

    public function handle(string $action, array $payload): array
    {
        return match ($action) {
            'filters'          => $this->model->getFilterOptions(),
            'rows'             => $this->model->getFilteredRows($payload),
            'q1_cse_trend'     => $this->model->cseTrendTopIITs(),
            'q2_toughest'      => $this->model->toughestBranches(10),
            'q3_gender'        => $this->model->genderSupernumeraryImpact(),
            'q4_newage_core'   => $this->model->newAgeVsCore(),
            'q5_iit_hierarchy' => $this->model->iitPreferenceRanking(),
            'q6_round_drop'    => $this->model->roundWiseDrop(),
            'q7_tradeoff'      => $this->model->branchVsIITTradeoff(),
            'q8_category_gap'  => $this->model->categoryCutoffGaps(),
            'q9_volatility'    => $this->model->highestVolatility(15),
            'q10_top100'       => $this->model->top100Monopoly(),
            default            => throw new InvalidArgumentException("Unknown action: {$action}"),
        };
    }
}
