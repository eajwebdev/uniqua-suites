<?php

declare(strict_types=1);

use Dompdf\Dompdf;
use Dompdf\Options;

require dirname(__DIR__).'/vendor/autoload.php';

$root = dirname(__DIR__);
$source = $root.'/docs/OWNER_HANDBOOK.html';
$destination = $root.'/docs/UNIQA_SUITES_OWNER_HANDBOOK.pdf';

$options = new Options;
$options->set('chroot', $root);
$options->set('isRemoteEnabled', false);
$options->set('isHtml5ParserEnabled', true);

$pdf = new Dompdf($options);
$html = file_get_contents($source);
if ($html === false) {
    throw new RuntimeException("Cannot read {$source}");
}

// Dompdf resolves local assets from the project chroot rather than the HTML file's folder.
$html = str_replace('../public/brand/uniqa-wordmark.png', 'public/brand/uniqa-wordmark.png', $html);
$pdf->loadHtml($html, 'UTF-8');
$pdf->setPaper('A4', 'portrait');
$pdf->render();

if (file_put_contents($destination, $pdf->output()) === false) {
    throw new RuntimeException("Cannot write {$destination}");
}

fwrite(STDOUT, "Generated {$destination} ({$pdf->getCanvas()->get_page_count()} pages)\n");
