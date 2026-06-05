<?php
declare(strict_types=1);

/**
 * API Front-Controller
 *
 * Existing analytics routes:
 *   GET/POST /api.php?action=filters
 *   POST     /api.php?action=rows            { filters... }
 *   GET      /api.php?action=q1_cse_trend    (and q2...q10)
 *   GET      /api.php?action=export_csv&f=<json encoded filters>
 *   GET      /api.php?action=export_pdf&f=<json encoded filters>
 *
 * AI routes:
 *   POST     /api.php?action=ai_ask          { question, conversation? }
 *   GET      /api.php?action=ai_history
 *   POST     /api.php?action=ai_export_pdf   { question, sql, data, chart, explanation }
 */

require __DIR__ . '/../config/Database.php';
require __DIR__ . '/../config/ai_config.php';

// Core
require __DIR__ . '/../src/Core/Response.php';

// Analytics
require __DIR__ . '/../src/Models/AllotmentModel.php';
require __DIR__ . '/../src/Controllers/AnalyticsController.php';
require __DIR__ . '/../src/Services/ExportService.php';

// AI layer
require __DIR__ . '/../src/Services/LLMProvider.php';
require __DIR__ . '/../src/Services/GroqProvider.php';
require __DIR__ . '/../src/Services/SqlValidator.php';
require __DIR__ . '/../src/Services/RagService.php';
require __DIR__ . '/../src/Services/NLQueryService.php';
require __DIR__ . '/../src/Controllers/AIController.php';

// Predictor layer
require __DIR__ . '/../src/Services/PredictorService.php';
require __DIR__ . '/../src/Controllers/PredictorController.php';

try {
    $action  = $_GET['action'] ?? '';
    $rawBody = file_get_contents('php://input');
    $payload = $rawBody ? (json_decode($rawBody, true) ?? []) : [];

    $pdo   = Database::connect();
    $model = new AllotmentModel($pdo);

    // ---------- export branches (stream directly, no JSON) ----------
    if ($action === 'export_csv') {
        $filters = json_decode($_GET['f'] ?? '{}', true) ?: [];
        $rows    = $model->getFilteredRows($filters, 20000);
        ExportService::toCsv($rows, 'josaa_filtered_' . date('Ymd_His') . '.csv');
    }

    if ($action === 'export_pdf') {
        $filters = json_decode($_GET['f'] ?? '{}', true) ?: [];
        $rows    = $model->getFilteredRows($filters, 20000);
        header('Content-Type: text/html; charset=utf-8');
        echo ExportService::toPrintableHtml($rows, 'JOSAA Filtered Report');
        exit;
    }

    if ($action === 'ai_export_pdf') {
        $payload = $payload ?: [];
        header('Content-Type: text/html; charset=utf-8');
        echo ExportService::aiReportHtml($payload);
        exit;
    }

    // ---------- AI JSON routes ----------
    if (str_starts_with($action, 'ai_')) {
        $llm     = new GroqProvider();
        $rag     = new RagService($pdo);
        $nlq     = new NLQueryService($pdo, $llm, $rag);
        $aiCtrl  = new AIController($nlq);

        $data = match ($action) {
            'ai_ask'     => $aiCtrl->ask($payload),
            'ai_history' => $aiCtrl->history(),
            'ai_rate'    => $aiCtrl->rate($payload),
            default      => throw new InvalidArgumentException("Unknown AI action: {$action}"),
        };
        Response::ok($data);
    }

    // ---------- Predictor JSON routes ----------
    if ($action === 'predictor_options' || str_starts_with($action, 'predict_')) {
        $predictor = new PredictorController(new PredictorService($pdo));

        $data = match ($action) {
            'predictor_options'      => $predictor->options(),
            'predict_by_rank'        => $predictor->predictByRank($payload),
            'predict_for_preference' => $predictor->predictForPreference($payload),
            default                  => throw new InvalidArgumentException("Unknown predictor action: {$action}"),
        };
        Response::ok($data);
    }

    // ---------- standard analytics JSON API ----------
    $controller = new AnalyticsController($model);
    Response::ok($controller->handle($action, $payload));

} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 400);
} catch (Throwable $e) {
    Response::error('Server error: ' . $e->getMessage(), 500);
}
