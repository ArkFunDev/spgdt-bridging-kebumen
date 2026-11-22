<?php
/**
 * Contoh konfigurasi / Configuration template.
 *
 * Salin ke `config.php` lalu isi nilai asli. `config.php` ada di .gitignore
 * dan TIDAK BOLEH di-commit.
 *
 *   copy config.example.php config.php        (Windows)
 *   cp config.example.php config.php          (Linux/macOS)
 */
return [
    // --- API SPGDT -------------------------------------------------------
    'api' => [
        'base_url'   => 'https://spgdt.example.go.id/index.php/api/', // akhiri dengan "/"
        'username'   => 'CHANGE_ME',
        'password'   => 'CHANGE_ME',
        'timeout'    => 30,
        // Verifikasi sertifikat TLS. Biarkan true di produksi.
        // Jika XAMPP Windows tidak punya CA bundle, unduh cacert.pem lalu isi 'ca_bundle'
        // (jangan matikan verify_ssl).
        'verify_ssl' => true,
        'ca_bundle'  => null, // contoh: 'C:\\xampp\\php\\extras\\ssl\\cacert.pem'
    ],

    // --- Database SIMRS (cukup akun READ-ONLY) -----------------------------
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'user' => 'CHANGE_ME',
        'pass' => 'CHANGE_ME',
        'name' => 'CHANGE_ME',
    ],

    // --- Kapasitas tempat tidur sesuai SK rumah sakit (contoh) -------------
    // terisi yang dikirim tidak akan pernah melebihi nilai ini.
    'capacity' => [
        'vvip' => 4,  'vip' => 12, 'kamar_utama' => 0,
        'kelas_1' => 35, 'kelas_2' => 40, 'kelas_3' => 129,
        'icu' => 24, 'iccu' => 7, 'peristi' => 14,
        'ruang_bersalin' => 10, 'poli' => 0, 'isolasi' => 30,
    ],

    // true = tampilkan error PHP di console (hanya untuk debugging)
    'debug' => false,
];
