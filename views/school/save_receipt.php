<?php
// save_receipt.php
header('Content-Type: application/json; charset=utf-8');

$input = file_get_contents('php://input');
if (!$input) { echo json_encode(["success"=>false,"message"=>"No data"]); exit; }

$data = json_decode($input, true);
if (!is_array($data) || !isset($data['image']) || !isset($data['filename'])) {
  echo json_encode(["success"=>false,"message"=>"Invalid payload"]); exit;
}

$img = $data['image'];
$filename = basename($data['filename']);
$folder = __DIR__ . '/receipts';
if (!is_dir($folder)) {
  if (!mkdir($folder, 0755, true)) {
    echo json_encode(["success"=>false,"message"=>"Failed to create receipts directory"]); exit;
  }
}

if (preg_match('/^data:image\/png;base64,/', $img)) {
  $img = substr($img, strpos($img, ',') + 1);
}
$img = base64_decode($img);
if ($img === false) { echo json_encode(["success"=>false,"message"=>"Invalid base64 image"]); exit; }

$path = $folder . '/' . $filename;
if (file_put_contents($path, $img) === false) {
  echo json_encode(["success"=>false,"message"=>"Failed to save file"]); exit;
}

echo json_encode(["success"=>true,"path"=>"receipts/".$filename]);
