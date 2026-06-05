<?php
header('Content-Type: application/json');

$host = '127.0.0.1';
$db   = 'josaa_portal';
$user = 'root';
$pass = '';

$dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$institute = $_GET['institute'] ?? '';
$branch    = $_GET['branch'] ?? '';
$quota     = $_GET['quota'] ?? '';
$seatType  = $_GET['seatType'] ?? '';
$gender    = $_GET['gender'] ?? '';

$sql = "
    SELECT f.year, f.closing_rank as cr
    FROM fact_allotment f
    JOIN dim_iit i ON f.iit_id = i.iit_id
    JOIN dim_branch b ON f.branch_id = b.branch_id
    JOIN dim_quota q ON f.quota_id = q.quota_id
    JOIN dim_seat_type s ON f.seat_type_id = s.seat_type_id
    JOIN dim_gender g ON f.gender_id = g.gender_id
    WHERE i.iit_name = :institute
      AND b.branch_name = :branch
      AND q.quota_code = :quota
      AND s.seat_type_code = :seatType
      AND g.gender_code = :gender
      AND (f.round_no = 6 OR f.round_no = (
          SELECT MAX(round_no) 
          FROM fact_allotment f2 
          WHERE f2.year = f.year
      ))
    ORDER BY f.year ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    'institute' => $institute,
    'branch'    => $branch,
    'quota'     => $quota,
    'seatType'  => $seatType,
    'gender'    => $gender
]);

$data = $stmt->fetchAll();
echo json_encode($data);
?>