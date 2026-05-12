<?php
/**
 * Loads FPDF with a reliable font directory (core fonts ship under common/lib/font/).
 * FPDF's default assumes fonts live next to fpdf.php — this keeps paths explicit.
 */

if (!defined('FPDF_FONTPATH')) {
    $iapFpdfFontDir = __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'font' . DIRECTORY_SEPARATOR;
    define('FPDF_FONTPATH', $iapFpdfFontDir);
}

/**
 * True when core metric files exist so FPDF will not fatal on SetFont('Arial','B',...).
 */
function iap_fpdf_runtime_ready(): bool
{
    $d = FPDF_FONTPATH;
    return is_file($d . 'helvetica.php')
        && is_file($d . 'helveticab.php')
        && is_file($d . 'helveticai.php')
        && is_file($d . 'helveticabi.php');
}

$__iap_fpdf_main = __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'fpdf.php';
if (is_readable($__iap_fpdf_main)) {
    require_once $__iap_fpdf_main;
}
