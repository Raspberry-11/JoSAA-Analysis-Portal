<?php
declare(strict_types=1);

/**
 * ExportService
 *
 * CSV export streams directly to the browser.
 * PDF export renders a printable HTML page that uses window.print() — keeps the
 * project dependency-free. To upgrade to a server-rendered PDF, drop in dompdf
 * (composer require dompdf/dompdf) and replace toPrintableHtml().
 */
final class ExportService
{
    public static function toCsv(array $rows, string $filename = 'josaa_export.csv'): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"{$filename}\"");
        header('Pragma: no-cache');
        header('Expires: 0');

        $fh = fopen('php://output', 'w');
        if (!empty($rows)) {
            fputcsv($fh, array_keys($rows[0]));
            foreach ($rows as $row) {
                fputcsv($fh, $row);
            }
        }
        fclose($fh);
        exit;
    }

    public static function toPrintableHtml(array $rows, string $title): string
    {
        $head = !empty($rows) ? array_keys($rows[0]) : [];

        ob_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($title) ?></title>
<style>
  body { font-family: system-ui, -apple-system, sans-serif; padding: 24px; color: #111; }
  h2 { margin-bottom: 16px; }
  table { border-collapse: collapse; width: 100%; font-size: 12px; }
  th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
  th { background: #f3f4f6; }
  .noprint { margin-bottom: 16px; padding: 8px 16px; cursor: pointer; }
  .meta { color: #6b7280; font-size: 12px; margin-bottom: 12px; }
  @media print { .noprint { display: none; } }
</style>
</head>
<body>
  <button class="noprint" onclick="window.print()">🖨️  Print / Save as PDF</button>
  <h2><?= htmlspecialchars($title) ?></h2>
  <div class="meta">Generated: <?= date('Y-m-d H:i') ?> · Rows: <?= count($rows) ?></div>
  <table>
    <thead>
      <tr>
        <?php foreach ($head as $h): ?>
          <th><?= htmlspecialchars((string) $h) ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <?php foreach ($r as $c): ?>
            <td><?= htmlspecialchars((string) $c) ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</body>
</html>
<?php
        return ob_get_clean();
    }

    /**
     * AI-answer PDF report. Renders: question, explanation, generated SQL, chart (as image), data table.
     * Called by POST /api.php?action=ai_export_pdf with the full AI response payload.
     */
    public static function aiReportHtml(array $payload): string
    {
        $question    = (string) ($payload['question']    ?? 'Untitled question');
        $sql         = (string) ($payload['sql']         ?? '');
        $explanation = (string) ($payload['explanation'] ?? '');
        $model       = (string) ($payload['model']       ?? 'unknown');
        $chartImage  = (string) ($payload['chart_image'] ?? '');  // optional: base64 PNG from frontend
        $data        = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $head        = !empty($data) ? array_keys($data[0]) : [];

        ob_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>AI Analysis Report</title>
<style>
  body { font-family: system-ui, -apple-system, sans-serif; padding: 32px; color: #111; line-height: 1.5; max-width: 900px; margin: 0 auto; }
  h1 { color: #4f46e5; margin-bottom: 4px; }
  .subtitle { color: #6b7280; font-size: 13px; margin-bottom: 24px; }
  .section { margin-top: 28px; }
  .section h3 { color: #374151; border-bottom: 2px solid #e5e7eb; padding-bottom: 6px; margin-bottom: 12px; }
  .question-box { background: #eef2ff; border-left: 4px solid #4f46e5; padding: 14px 18px; border-radius: 6px; font-size: 16px; }
  .explanation { background: #f9fafb; padding: 12px 16px; border-radius: 6px; font-style: italic; color: #4b5563; }
  pre { background: #1e1e1e; color: #d4d4d4; padding: 16px; border-radius: 8px; overflow-x: auto; font-size: 12px; line-height: 1.4; white-space: pre-wrap; word-wrap: break-word; }
  table { border-collapse: collapse; width: 100%; font-size: 11px; margin-top: 8px; }
  th, td { border: 1px solid #d1d5db; padding: 6px 8px; text-align: left; }
  th { background: #f3f4f6; font-weight: 600; }
  .chart-img { max-width: 100%; border: 1px solid #e5e7eb; border-radius: 6px; margin: 12px 0; }
  .meta { color: #9ca3af; font-size: 11px; margin-top: 24px; padding-top: 16px; border-top: 1px solid #e5e7eb; }
  .noprint { margin-bottom: 24px; padding: 10px 20px; background: #4f46e5; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500; }
  .noprint:hover { background: #4338ca; }
  @media print {
    body { max-width: none; padding: 20px; }
    .noprint { display: none; }
    pre { font-size: 10px; }
  }
</style>
</head>
<body>
  <button class="noprint" onclick="window.print()">🖨️  Save as PDF</button>

  <h1>JOSAA AI Analysis Report</h1>
  <div class="subtitle">Generated on <?= date('F j, Y · H:i') ?> · Model: <?= htmlspecialchars($model) ?></div>

  <div class="section">
    <h3>Question</h3>
    <div class="question-box"><?= htmlspecialchars($question) ?></div>
  </div>

  <?php if ($explanation !== ''): ?>
  <div class="section">
    <h3>Summary</h3>
    <div class="explanation"><?= htmlspecialchars($explanation) ?></div>
  </div>
  <?php endif; ?>

  <?php if ($chartImage !== ''): ?>
  <div class="section">
    <h3>Visualization</h3>
    <img src="<?= htmlspecialchars($chartImage) ?>" class="chart-img" alt="Chart">
  </div>
  <?php endif; ?>

  <?php if ($sql !== ''): ?>
  <div class="section">
    <h3>Generated SQL</h3>
    <pre><?= htmlspecialchars($sql) ?></pre>
  </div>
  <?php endif; ?>

  <?php if (!empty($data)): ?>
  <div class="section">
    <h3>Result Data (<?= count($data) ?> rows)</h3>
    <table>
      <thead>
        <tr><?php foreach ($head as $h): ?><th><?= htmlspecialchars((string) $h) ?></th><?php endforeach; ?></tr>
      </thead>
      <tbody>
        <?php foreach (array_slice($data, 0, 100) as $r): ?>
          <tr><?php foreach ($r as $c): ?><td><?= htmlspecialchars((string) $c) ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (count($data) > 100): ?>
      <div class="meta">Showing first 100 of <?= count($data) ?> rows.</div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="meta">
    JOSAA Analytics Portal · AI-generated report.
    The SQL above was produced by a language model and validated before execution.
  </div>
</body>
</html>
<?php
        return ob_get_clean();
    }
}
