<?php
/**
 * Bridging SPGDT - Kabupaten Kebumen
 *
 * Mengirim ketersediaan tempat tidur (kamar) dan stok darah dari database SIMRS
 * ke API SPGDT secara otomatis. Dijalankan terjadwal (Windows Task Scheduler).
 *
 * Privasi: skrip ini HANYA membaca dan mengirim data agregat (jumlah). Tidak ada
 * data identitas atau rekam medis pasien yang dibaca, disimpan, maupun dikirim.
 *
 * Exit code: 0 = sukses, 1 = sebagian/seluruh pengiriman gagal,
 *            2 = instance lain sedang berjalan, 3 = konfigurasi tidak valid,
 *            4 = gagal autentikasi, 5 = gagal koneksi database.
 */

declare(strict_types=1);

error_reporting(E_ALL);
date_default_timezone_set('Asia/Jakarta');

// ===== Konfigurasi =====
$configFile = getenv('SPGDT_CONFIG') ?: __DIR__ . '/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "Config tidak ditemukan: salin config.example.php menjadi config.php\n");
    exit(3);
}
$config = require $configFile;
foreach (['api', 'db', 'capacity'] as $section) {
    if (!isset($config[$section]) || !is_array($config[$section])) {
        fwrite(STDERR, "Config tidak valid: bagian '$section' hilang\n");
        exit(3);
    }
}
ini_set('display_errors', !empty($config['debug']) ? '1' : '0');

$lockFile    = __DIR__ . '/bridging.lock';
$logFile     = __DIR__ . '/log_bridging.txt';
$summaryFile = __DIR__ . '/summary_data_spgdt.txt';
$tokenFile   = __DIR__ . '/token_spgdt.json';
$sessionId   = date('Ymd_His');

// ===== Cegah eksekusi ganda =====
$lockHandle = fopen($lockFile, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo '[' . date('Y-m-d H:i:s') . "] Instance lain masih berjalan. Dibatalkan.\n";
    exit(2);
}
register_shutdown_function(function () use ($lockHandle, $lockFile) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    @unlink($lockFile);
});

// ===== Logging (dengan rotasi 2 MB) =====
function tulisLog(string $pesan): void
{
    global $logFile, $sessionId;
    $entry = '[' . date('Y-m-d H:i:s') . "][RUN:$sessionId] $pesan\n";

    if (is_file($logFile) && filesize($logFile) >= 2 * 1024 * 1024) {
        $arsip = __DIR__ . '/log_bridging_' . date('Ymd_His') . '.txt';
        @rename($logFile, $arsip);
    }
    @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    echo $entry;
}

// ===== HTTP =====
/** @return array{body:string|false, code:int, error:string} */
function httpPost(string $url, array $fields, ?string $token = null): array
{
    global $config;
    $api = $config['api'];

    $ch = curl_init($url);
    $opts = [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => (int)($api['timeout'] ?? 30),
        CURLOPT_SSL_VERIFYPEER => (bool)($api['verify_ssl'] ?? true),
        CURLOPT_SSL_VERIFYHOST => ($api['verify_ssl'] ?? true) ? 2 : 0,
    ];
    if (!empty($api['ca_bundle'])) {
        $opts[CURLOPT_CAINFO] = $api['ca_bundle'];
    }
    if ($token !== null) {
        $opts[CURLOPT_HTTPHEADER] = ["Authorization: Bearer $token"];
    }
    curl_setopt_array($ch, $opts);

    $body  = curl_exec($ch);
    $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return ['body' => $body, 'code' => $code, 'error' => $error];
}

// ===== Token =====
function dapatkanTokenBaru(): string|false
{
    global $config;
    $api = $config['api'];
    tulisLog('Login ke API untuk mendapatkan token baru...');

    $r = httpPost($api['base_url'] . 'Api/login', [
        'username' => $api['username'],
        'password' => $api['password'],
    ]);

    if ($r['body'] === false || $r['code'] >= 400) {
        tulisLog("GAGAL login: HTTP {$r['code']} {$r['error']}");
        return false;
    }

    $data  = json_decode($r['body'], true);
    $token = $data['token'] ?? $data['data']['token'] ?? $data['access_token'] ?? null;
    if (!is_string($token) || $token === '') {
        tulisLog('Login gagal: response tidak mengandung token');
        return false;
    }
    tulisLog('Login berhasil, token baru didapatkan');
    return $token;
}

function simpanToken(string $token): void
{
    global $tokenFile;
    $now  = date('Y-m-d H:i:s');
    $json = json_encode(['token' => $token, 'created_at' => $now, 'last_refresh' => $now], JSON_PRETTY_PRINT);
    if (file_put_contents($tokenFile, $json, LOCK_EX) === false) {
        tulisLog('Peringatan: gagal menyimpan token ke file');
        return;
    }
    @chmod($tokenFile, 0600);
}

function bacaToken(): ?string
{
    global $tokenFile;
    if (!is_file($tokenFile)) {
        return null;
    }
    $data = json_decode((string)file_get_contents($tokenFile), true);
    return isset($data['token']) && is_string($data['token']) ? $data['token'] : null;
}

/** Token diperbarui tiap pergantian bulan, dan preventif mulai tanggal 28. */
function perluRefreshToken(): bool
{
    global $tokenFile;
    if (!is_file($tokenFile)) {
        return true;
    }
    $data = json_decode((string)file_get_contents($tokenFile), true);
    if (!isset($data['last_refresh'])) {
        return true;
    }

    $last = new DateTime($data['last_refresh']);
    $now  = new DateTime();

    if ($last->format('Y-m') !== $now->format('Y-m')) {
        tulisLog('Pergantian bulan, token perlu diperbarui');
        return true;
    }
    if ((int)$now->format('d') >= 28 && $last->format('Y-m-d') !== $now->format('Y-m-d')) {
        tulisLog('Mendekati akhir bulan, refresh token preventif');
        return true;
    }
    return false;
}

function refreshToken(): string|false
{
    $token = dapatkanTokenBaru();
    if ($token !== false) {
        simpanToken($token);
    }
    return $token;
}

function dapatkanTokenValid(): string|false
{
    if (perluRefreshToken()) {
        $token = refreshToken();
        if ($token !== false) {
            return $token;
        }
    }
    return bacaToken() ?? refreshToken();
}

function tokenDitolak(string $body, int $code): bool
{
    if ($code === 401 || $code === 403) {
        return true;
    }
    $lower = strtolower($body);
    foreach (['unauthorized', 'invalid token', 'token expired', 'token not valid', 'unauthenticated'] as $kw) {
        if (str_contains($lower, $kw)) {
            return true;
        }
    }
    return false;
}

// ===== Kirim data (retry sekali jika token ditolak) =====
/** @return array{success:bool, response:string, http_code:int} */
function kirimData(string $endpoint, array $data, string &$token, string $nama): array
{
    global $config;
    $url    = $config['api']['base_url'] . $endpoint;
    $result = ['success' => false, 'response' => '', 'http_code' => 0];

    for ($attempt = 1; $attempt <= 2; $attempt++) {
        tulisLog("Mengirim data $nama (percobaan ke-$attempt)...");
        $r = httpPost($url, $data, $token);
        $result['http_code'] = $r['code'];

        if ($r['body'] === false) {
            tulisLog("GAGAL kirim $nama: {$r['error']}");
            $result['response'] = "CURL Error: {$r['error']}";
            return $result;
        }

        if ($r['code'] < 400) {
            tulisLog("BERHASIL kirim $nama: {$r['body']}");
            return ['success' => true, 'response' => $r['body'], 'http_code' => $r['code']];
        }

        $result['response'] = "HTTP {$r['code']}: {$r['body']}";
        if ($attempt === 1 && tokenDitolak($r['body'], $r['code'])) {
            tulisLog("Token ditolak (HTTP {$r['code']}), refresh token lalu kirim ulang...");
            $baru = refreshToken();
            if ($baru === false) {
                $result['response'] = 'GAGAL refresh token';
                return $result;
            }
            $token = $baru;
            continue;
        }
        tulisLog("HTTP Error $nama: {$result['response']}");
        return $result;
    }
    return $result;
}

// ===== Ringkasan data terkirim (agregat saja) =====
function simpanRingkasan(string $jenis, array $data, bool $ok, string $response = ''): void
{
    global $summaryFile, $sessionId;
    $sep = str_repeat('=', 70) . "\n";
    $out = "\n$sep"
         . "RUN SESSION: $sessionId\n"
         . 'TANGGAL/JAM: ' . date('Y-m-d H:i:s') . "\n"
         . "JENIS DATA: $jenis\n"
         . 'STATUS: ' . ($ok ? 'BERHASIL TERKIRIM' : 'GAGAL TERKIRIM') . "\n"
         . ($response !== '' ? "RESPONSE API: $response\n" : '')
         . $sep;

    if ($jenis === 'KAMAR') {
        $out .= sprintf("%-25s | %-10s | %-10s | %-10s\n", 'JENIS KAMAR', 'KAPASITAS', 'TERISI', 'TERSEDIA')
              . str_repeat('-', 70) . "\n";
        $label = [
            'vvip' => 'VVIP', 'vip' => 'VIP', 'kelas_1' => 'Kelas 1', 'kelas_2' => 'Kelas 2',
            'kelas_3' => 'Kelas 3', 'icu' => 'ICU', 'iccu' => 'ICCU', 'peristi' => 'Peristi',
            'ruang_bersalin' => 'Ruang Bersalin', 'isolasi' => 'Isolasi',
            'kamar_utama' => 'Kamar Utama', 'poli' => 'Poli',
        ];
        foreach ($label as $key => $nama) {
            $out .= sprintf("%-25s | %-10d | %-10d | %-10d\n", $nama,
                $data[$key . '_kapasitas'] ?? 0, $data[$key . '_terisi'] ?? 0, $data[$key . '_tersedia'] ?? 0);
        }
        $out .= str_repeat('-', 70) . "\n"
              . sprintf("%-25s | %-10d\n", 'UGD (Pasien Hari Ini)', $data['ugd'] ?? 0)
              . sprintf("%-25s | %-10d\n", 'Ventilator (Aktif)', $data['ventilator'] ?? 0);
    } elseif ($jenis === 'DARAH') {
        $out .= sprintf("%-20s | %-15s\n", 'GOLONGAN DARAH', 'JUMLAH KANTONG') . str_repeat('-', 40) . "\n";
        $total = 0;
        foreach (['a' => 'A', 'b' => 'B', 'ab' => 'AB', 'o' => 'O'] as $k => $g) {
            $n = (int)($data["golongan_$k"] ?? 0);
            $total += $n;
            $out .= sprintf("%-20s | %-15d\n", "Golongan $g", $n);
        }
        $out .= str_repeat('-', 40) . "\n" . sprintf("%-20s | %-15d\n", 'TOTAL KANTONG', $total);
    }
    $out .= $sep . "\n";

    if (is_file($summaryFile) && filesize($summaryFile) >= 5 * 1024 * 1024) {
        @rename($summaryFile, __DIR__ . '/summary_data_spgdt_' . date('Ymd_His') . '.txt');
    }
    @file_put_contents($summaryFile, $out, FILE_APPEND | LOCK_EX);
}

// ===== Query database =====
/** Satu baris hasil (assoc) atau null jika gagal. Mengembalikan [] jika kosong. */
function ambilBaris(mysqli $db, string $sql, string $label, string $types = '', array $params = []): ?array
{
    try {
        $stmt = $db->prepare($sql);
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        return $row;
    } catch (mysqli_sql_exception $e) {
        tulisLog("SQL Error ($label): " . $e->getMessage());
        return null;
    }
}

/** Kamar terisi per kategori. Hanya menghitung baris kamar, tanpa data pasien. */
function sqlKamarTerisi(): string
{
    // Kamar khusus yang tidak boleh ikut terhitung sebagai kelas 1/2/3.
    // (nowdoc: backslash ditulis literal; MySQL mengubah '\\.' menjadi regex '\.')
    $bukanKelasBiasa = <<<'SQL'
        AND kd_kamar NOT REGEXP '^IC\\.'
        AND kd_kamar NOT REGEXP '^ICC\\.'
        AND kd_kamar NOT REGEXP '^P\\.'
        AND kd_kamar NOT REGEXP '^VKS\\.'
        AND kd_kamar NOT REGEXP '^K\\.'
        AND kd_kamar NOT REGEXP '\\.Imo'
        AND kd_kamar NOT REGEXP '\\.ISO$'
SQL;

    $kelas = static fn(string $nama) =>
        "SUM(CASE WHEN TRIM(UPPER(kelas)) = '$nama' $bukanKelasBiasa AND statusdata = '1' THEN 1 ELSE 0 END)";

    return <<<SQL
    SELECT
        SUM(CASE WHEN kd_kamar REGEXP '^IC\\\\.'  AND statusdata = '1' THEN 1 ELSE 0 END) AS icu,
        SUM(CASE WHEN kd_kamar REGEXP '^ICC\\\\.' AND statusdata = '1' THEN 1 ELSE 0 END) AS iccu,
        SUM(CASE WHEN kd_kamar REGEXP '^P\\\\.'   AND statusdata = '1' THEN 1 ELSE 0 END) AS peristi,
        SUM(CASE WHEN kd_kamar REGEXP '^VKS\\\\.' AND statusdata = '1' THEN 1 ELSE 0 END) AS ruang_bersalin,
        SUM(CASE WHEN (kd_kamar REGEXP '^K\\\\.' OR kd_kamar REGEXP '\\\\.Imo' OR kd_kamar REGEXP '\\\\.ISO$')
                 AND statusdata = '1' THEN 1 ELSE 0 END) AS isolasi,
        SUM(CASE WHEN TRIM(UPPER(kelas)) = 'KELAS VVIP' AND statusdata = '1' THEN 1 ELSE 0 END) AS vvip,
        SUM(CASE WHEN TRIM(UPPER(kelas)) = 'KELAS VIP' AND kd_kamar NOT LIKE '%Box Bayi VIP%'
                 AND statusdata = '1' THEN 1 ELSE 0 END) AS vip,
        {$kelas('KELAS 1')} AS kelas_1,
        {$kelas('KELAS 2')} AS kelas_2,
        {$kelas('KELAS 3')} AS kelas_3
    FROM kamar
    WHERE status = 'ISI'
SQL;
}

// ============================================================
// MAIN
// ============================================================
tulisLog("=== [RUN #$sessionId] Bridging SPGDT dimulai ===");

$token = dapatkanTokenValid();
if ($token === false) {
    tulisLog('GAGAL mendapatkan token. Proses dihentikan.');
    exit(4);
}
tulisLog('Token valid tersedia');

// ----- Koneksi DB -----
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = new mysqli(
        $config['db']['host'], $config['db']['user'], $config['db']['pass'],
        $config['db']['name'], (int)($config['db']['port'] ?? 3306)
    );
    $db->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    tulisLog('Koneksi DB gagal: ' . $e->getMessage());
    exit(5);
}
tulisLog('Koneksi DB berhasil');

$tgl = date('Y-m-d');

// ----- Kamar -----
// Jika salah satu query gagal, kiriman kamar DIBATALKAN. Mengirim nol akan
// menimpa data valid di SPGDT dengan informasi ketersediaan yang menyesatkan.
$terisiRow = ambilBaris($db, sqlKamarTerisi(), 'Hitung Kamar');
$ugdRow    = ambilBaris($db,
    "SELECT COUNT(*) AS total FROM reg_periksa
     WHERE kd_poli = 'IGDK' AND tgl_registrasi = ? AND stts = 'Sudah' AND status_lanjut = 'Ralan'",
    'UGD', 's', [$tgl]);
$ventRow   = ambilBaris($db,
    'SELECT COUNT(*) AS total FROM catatan_observasi_ventilator WHERE tgl_perawatan = ?',
    'Ventilator', 's', [$tgl]);

$kamar_result = ['success' => false, 'response' => 'Query DB gagal, kiriman dibatalkan', 'http_code' => 0];

if ($terisiRow !== null && $ugdRow !== null && $ventRow !== null) {
    $jumlah = array_map('intval', $terisiRow);
    tulisLog('Data kamar dari DB berhasil diambil');
    tulisLog('Data UGD: ' . (int)($ugdRow['total'] ?? 0));
    tulisLog('Data Ventilator: ' . (int)($ventRow['total'] ?? 0));

    $kamar_data = [];
    foreach ($config['capacity'] as $k => $kapasitas) {
        $kapasitas = (int)$kapasitas;
        $raw    = (int)($jumlah[$k] ?? 0);
        $terisi = min($raw, $kapasitas); // tidak boleh melebihi kapasitas SK

        if ($raw > $kapasitas) {
            tulisLog(strtoupper($k) . " OVERKAPASITAS - DB: $raw, dikirim: $terisi (kapasitas SK: $kapasitas)");
        }
        $kamar_data[$k . '_kapasitas']  = $kapasitas;
        $kamar_data[$k . '_terisi']     = $terisi;
        $kamar_data[$k . '_tersedia']   = max($kapasitas - $terisi, 0);
        $kamar_data[$k . '_keterangan'] = '-';
    }
    $kamar_data += [
        'jumlah_tempat_tidur_kapasitas'  => 0,
        'jumlah_tempat_tidur_terisi'     => 0,
        'jumlah_tempat_tidur_tersedia'   => 0,
        'jumlah_tempat_tidur_keterangan' => '-',
        'ugd'                   => (int)($ugdRow['total'] ?? 0),
        'ugd_keterangan'        => '-',
        'ventilator'            => (int)($ventRow['total'] ?? 0),
        'ventilator_keterangan' => '-',
    ];

    tulisLog('Mengirim data KAMAR (menimpa data lama di SPGDT)...');
    $kamar_result = kirimData('Api/update_kamar', $kamar_data, $token, 'Kamar');
    simpanRingkasan('KAMAR', $kamar_data, $kamar_result['success'], $kamar_result['response']);
} else {
    tulisLog('Pengiriman KAMAR dibatalkan karena query database gagal');
}

// ----- Darah -----
$darah_result = ['success' => false, 'response' => 'Query DB gagal, kiriman dibatalkan', 'http_code' => 0];
$darah = ['A' => 0, 'B' => 0, 'AB' => 0, 'O' => 0];
$darahOk = true;

try {
    // Satu baris = satu kantong darah berstatus 'Ada'.
    $res = $db->query(
        "SELECT UPPER(golongan_darah) AS gd, COUNT(*) AS total
         FROM utd_stok_darah WHERE status = 'Ada' GROUP BY UPPER(golongan_darah)"
    );
    while ($row = $res->fetch_assoc()) {
        if (isset($darah[$row['gd']])) {
            $darah[$row['gd']] = (int)$row['total'];
        }
    }
    $res->free();
    tulisLog('Data darah: A=' . $darah['A'] . ', B=' . $darah['B'] . ', AB=' . $darah['AB'] . ', O=' . $darah['O']);
} catch (mysqli_sql_exception $e) {
    $darahOk = false;
    tulisLog('SQL Error (Darah): ' . $e->getMessage());
}

if ($darahOk) {
    $darah_data = [
        'golongan_a'  => $darah['A'],
        'golongan_b'  => $darah['B'],
        'golongan_ab' => $darah['AB'],
        'golongan_o'  => $darah['O'],
    ];
    tulisLog('Mengirim data DARAH (menimpa data lama di SPGDT)...');
    $darah_result = kirimData('Api/update_darah', $darah_data, $token, 'Darah');
    simpanRingkasan('DARAH', $darah_data, $darah_result['success'], $darah_result['response']);
} else {
    tulisLog('Pengiriman DARAH dibatalkan karena query database gagal');
}

$db->close();

// ----- Ringkasan -----
tulisLog('Kamar: ' . ($kamar_result['success'] ? 'BERHASIL' : 'GAGAL'));
tulisLog('Darah: ' . ($darah_result['success'] ? 'BERHASIL' : 'GAGAL'));
tulisLog("=== [RUN #$sessionId] Selesai ===");

exit(($kamar_result['success'] && $darah_result['success']) ? 0 : 1);
