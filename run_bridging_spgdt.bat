@echo off
rem Menjalankan bridging SPGDT. Folder skrip dipakai sebagai working directory,
rem jadi repo bisa ditaruh di mana saja (tidak harus C:\spgdt).
rem Ubah PHP_BIN jika PHP tidak di lokasi XAMPP default.
setlocal
cd /d "%~dp0"
if not defined PHP_BIN set "PHP_BIN=C:\xampp\php\php.exe"
"%PHP_BIN%" -f "bridging_update_spgdt.php"
exit /b %ERRORLEVEL%
