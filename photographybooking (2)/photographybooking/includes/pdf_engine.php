<?php
// includes/pdf_engine.php - Lightweight Pure-PHP Vector PDF Generator
// Generates standard, 100% compliant PDF-1.4 documents without external dependencies

class SimplePDF {
    protected $width = 595.28;  // A4 Width in points
    protected $height = 841.89; // A4 Height in points
    protected $objects = [];
    protected $pageContent = '';
    protected $fontIds = [];

    public function __construct() {
        $this->fontIds = [
            'Helvetica'      => 'F1',
            'Helvetica-Bold' => 'F2',
            'Times-Roman'    => 'F3',
            'Courier'        => 'F4'
        ];
    }

    // Convert top-left Y coordinate to standard PDF bottom-left Y coordinate
    protected function toPdfY($y) {
        return $this->height - $y;
    }

    // Escape text for PDF string literal
    protected function escape($str) {
        $str = str_replace('\\', '\\\\', $str);
        $str = str_replace('(', '\\(', $str);
        $str = str_replace(')', '\\)', $str);
        // Replace non-ASCII / multi-byte characters with ASCII equivalents
        $str = str_replace(['₹', '•', '’', '“', '”', '–', '—'], ['Rs. ', '*', "'", '"', '"', '-', '-'], $str);
        return $str;
    }

    // Draw a rectangle
    public function rect($x, $y, $w, $h, $fillColor = null, $strokeColor = null, $lineWidth = 1) {
        $pdfY = $this->toPdfY($y + $h);
        $stream = "q\n";
        if ($strokeColor !== null) {
            $stream .= sprintf("%.2f %.2f %.2f RG\n", $strokeColor[0]/255, $strokeColor[1]/255, $strokeColor[2]/255);
            $stream .= sprintf("%.2f w\n", $lineWidth);
        }
        if ($fillColor !== null) {
            $stream .= sprintf("%.2f %.2f %.2f rg\n", $fillColor[0]/255, $fillColor[1]/255, $fillColor[2]/255);
        }
        $stream .= sprintf("%.2f %.2f %.2f %.2f re\n", $x, $pdfY, $w, $h);
        if ($fillColor !== null && $strokeColor !== null) {
            $stream .= "B\n";
        } elseif ($fillColor !== null) {
            $stream .= "f\n";
        } elseif ($strokeColor !== null) {
            $stream .= "S\n";
        }
        $stream .= "Q\n";
        $this->pageContent .= $stream;
    }

    // Draw a straight line
    public function line($x1, $y1, $x2, $y2, $color = [200, 200, 200], $lineWidth = 1) {
        $pdfY1 = $this->toPdfY($y1);
        $pdfY2 = $this->toPdfY($y2);
        $stream = "q\n";
        $stream .= sprintf("%.2f %.2f %.2f RG\n", $color[0]/255, $color[1]/255, $color[2]/255);
        $stream .= sprintf("%.2f w\n", $lineWidth);
        $stream .= sprintf("%.2f %.2f m\n", $x1, $pdfY1);
        $stream .= sprintf("%.2f %.2f l\n", $x2, $pdfY2);
        $stream .= "S\nQ\n";
        $this->pageContent .= $stream;
    }

    // Draw text with alignment support
    public function text($text, $x, $y, $fontSize = 10, $fontType = 'Helvetica', $color = [50, 50, 50], $align = 'left') {
        $fontCode = $this->fontIds[$fontType] ?? 'F1';
        $pdfY = $this->toPdfY($y);
        $escaped = $this->escape($text);

        // Approximate string width in points for Helvetica
        $approxCharWidth = ($fontType === 'Helvetica-Bold') ? ($fontSize * 0.58) : ($fontSize * 0.52);
        $textWidth = strlen($text) * $approxCharWidth;

        $targetX = $x;
        if ($align === 'right') {
            $targetX = $x - $textWidth;
        } elseif ($align === 'center') {
            $targetX = $x - ($textWidth / 2);
        }

        $stream = "BT\n";
        $stream .= sprintf("/%s %.2f Tf\n", $fontCode, $fontSize);
        $stream .= sprintf("%.2f %.2f %.2f rg\n", $color[0]/255, $color[1]/255, $color[2]/255);
        $stream .= sprintf("%.2f %.2f Td\n", $targetX, $pdfY);
        $stream .= "(" . $escaped . ") Tj\n";
        $stream .= "ET\n";
        $this->pageContent .= $stream;
    }

    // Compile and return standard PDF binary string with valid xref
    public function render() {
        $out = "%PDF-1.4\n";
        $offsets = [];

        // 1 0 obj: Catalog
        $offsets[1] = strlen($out);
        $out .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";

        // 2 0 obj: Pages
        $offsets[2] = strlen($out);
        $out .= "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";

        // 3 0 obj: Page
        $offsets[3] = strlen($out);
        $out .= "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 " . $this->width . " " . $this->height . "] /Resources 4 0 R /Contents 9 0 R >>\nendobj\n";

        // 4 0 obj: Resources
        $offsets[4] = strlen($out);
        $out .= "4 0 obj\n<< /Font << /F1 5 0 R /F2 6 0 R /F3 7 0 R /F4 8 0 R >> >>\nendobj\n";

        // 5 0 obj: Font Helvetica
        $offsets[5] = strlen($out);
        $out .= "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";

        // 6 0 obj: Font Helvetica-Bold
        $offsets[6] = strlen($out);
        $out .= "6 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>\nendobj\n";

        // 7 0 obj: Font Times-Roman
        $offsets[7] = strlen($out);
        $out .= "7 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Times-Roman >>\nendobj\n";

        // 8 0 obj: Font Courier
        $offsets[8] = strlen($out);
        $out .= "8 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>\nendobj\n";

        // 9 0 obj: Contents Stream
        $offsets[9] = strlen($out);
        $streamLen = strlen($this->pageContent);
        $out .= "9 0 obj\n<< /Length " . $streamLen . " >>\nstream\n" . $this->pageContent . "\nendstream\nendobj\n";

        // xref table
        $xrefOffset = strlen($out);
        $totalObjs = 10;
        $out .= "xref\n0 " . $totalObjs . "\n";
        $out .= "0000000000 65535 f \n";
        for ($i = 1; $i <= 9; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        // trailer
        $out .= "trailer\n<< /Size " . $totalObjs . " /Root 1 0 R >>\n";
        $out .= "startxref\n" . $xrefOffset . "\n%%EOF\n";

        return $out;
    }

    // Save PDF to file path
    public function saveToFile($filepath) {
        $dir = dirname($filepath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return file_put_contents($filepath, $this->render()) !== false;
    }

    // Output to browser for download
    public function download($filename = 'invoice.pdf') {
        $pdfData = $this->render();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdfData));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        echo $pdfData;
        exit;
    }

    // Output to browser for inline display
    public function inline($filename = 'invoice.pdf') {
        $pdfData = $this->render();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdfData));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        echo $pdfData;
        exit;
    }
}
?>