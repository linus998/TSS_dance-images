<?php // generateQR.php

if (!isset($_GET['data']) || empty($_GET['data'])) {
    http_response_code(400);
    echo 'Missing "data" parameter.';
    exit;
}

$data = $_GET['data'];

$logoUrl = "https://i.ibb.co/8DnzG2cD/boot-image-removebg-preview.png";

$postData = json_encode([
    "data" => $data,
    "config" => [
        "body" => "square",
        "eye" => "frame1",
        "eyeBall" => "ball15",
        "bgColor" => "#ffffffff",
        "bodyColor" => "#000000",
        "logo" => $logoUrl,
        "logoMode" => "clean"
    ],
    "size" => 300,
    "download" => false,
    "file" => "png"
]);


$ch = curl_init('https://api.qrcode-monkey.com/qr/custom');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($httpCode === 200 && $response !== false) {
    header('Content-Type: image/png');
    echo $response;
} else {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo "Failed to generate QR code. HTTP status code: $httpCode";
}

curl_close($ch);