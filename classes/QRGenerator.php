<?php

class QRGenerator {
    private $qrDir = 'barcodes/products/';
    
    public function __construct() {
        // Ensure directory exists
        if (!file_exists($this->qrDir)) {
            // Suppress warnings so JSON endpoints don't get HTML noise like "<br />Warning ..."
            @mkdir($this->qrDir, 0777, true);
        }
    }
    
    /**
     * Generate a Code128 barcode PNG for a product and save it locally.
     * @param int $productId Product ID
     * @param string $productCode Product Code (used as barcode data)
     * @param string $productName Product Name
     * @param float $productPrice Product Price
     * @return string|null Path to generated barcode or null on failure
     */
    public function generateProductQR($productId, $productCode, $productName, $productPrice) {
        // Encode minimal JSON so scanners can recover name and code
        $payload = json_encode([
            'product_code' => $productCode,
            'name' => $productName
        ]);
        $barcodeData = $payload !== false ? $payload : $productCode;
        
        // Generate filename
        $filename = $this->qrDir . $productCode . '_' . time() . '.png';
        
        // Use the public bwip-js API to render Code128 barcodes
        $barcodeUrl = $this->getBarcodeUrl($barcodeData);
        
        // Download and save the barcode image
        $barcodeImage = @file_get_contents($barcodeUrl);
        if ($barcodeImage !== false) {
            if (@file_put_contents($filename, $barcodeImage) !== false) {
                return $filename;
            }
        }
        
        return null;
    }
    
    /**
     * Generate Code128 barcode URL for display (without saving)
     * @param string $text Data to encode (use product code or SKU)
     * @param int $scale Barcode scale factor (width)
     * @param int $height Barcode height in millimeters (approx)
     * @return string URL to barcode image
     */
    public function getBarcodeUrl($text, $scale = 3, $height = 12) {
        $encoded = urlencode($text);
        return "https://bwipjs-api.metafloor.com/?bcid=code128&text={$encoded}&scale={$scale}&height={$height}&includetext&textxalign=center";
    }
    
    /**
     * Backward-compatible alias: now returns a barcode URL instead of QR.
     * @param mixed $data Data to encode (string or array)
     * @param int $size Ignored for barcodes; kept for signature compatibility
     * @return string URL to barcode image
     */
    public function getQRCodeUrl($data, $size = 200) {
        // If an array is provided, fall back to JSON string
        $text = is_array($data) ? json_encode($data) : (string) $data;
        return $this->getBarcodeUrl($text);
    }
    
    /**
     * Generate multiple barcode URLs for bulk printing
     * @param array $products Array of products
     * @return array Array of barcode URLs keyed with product info
     */
    public function generateBulkQRCodes($products) {
        $qrCodes = [];
        foreach ($products as $product) {
            $qrCodes[] = [
                'product' => $product,
                'qr_url' => $this->getBarcodeUrl($product['product_code'])
            ];
        }
        return $qrCodes;
    }
    
    /**
     * Delete barcode file
     * @param string $qrPath Path to barcode
     * @return bool Success status
     */
    public function deleteQRCode($qrPath) {
        if (file_exists($qrPath)) {
            return unlink($qrPath);
        }
        return false;
    }
}
?>
