<?php

require_once __DIR__ . "/PHP-RCCServiceSoap/Assemblies/Roblox/Grid/Rcc/Job.php";
require_once __DIR__ . "/PHP-RCCServiceSoap/Assemblies/Roblox/Grid/Rcc/LuaType.php";
require_once __DIR__ . "/PHP-RCCServiceSoap/Assemblies/Roblox/Grid/Rcc/LuaValue.php";
require_once __DIR__ . "/PHP-RCCServiceSoap/Assemblies/Roblox/Grid/Rcc/ScriptExecution.php";
require_once __DIR__ . "/PHP-RCCServiceSoap/Assemblies/Roblox/Grid/Rcc/Status.php";
require_once __DIR__ . "/PHP-RCCServiceSoap/Assemblies/Roblox/Grid/Rcc/RCCServiceSoap.php";

use Roblox\Grid\Rcc\RCCServiceSoap;
use Roblox\Grid\Rcc\Job;
use Roblox\Grid\Rcc\ScriptExecution;
use Roblox\Grid\Rcc\LuaValue;
use Roblox\Grid\Rcc\LuaType;
use Roblox\Grid\Rcc\Status;


$userId = 1;

$outputDirectory = __DIR__ . "/output";
$assetDirectory = $outputDirectory . "/assets";
$avatarPath = $outputDirectory . "/avatar.json";
$outputPath = $outputDirectory . "/" . $userId . ".png";

$rccContentDirectory = __DIR__ . "/RCCService/content";


if (!is_dir($outputDirectory)) {
    mkdir($outputDirectory, 0777, true);
}

if (!is_dir($assetDirectory)) {
    mkdir($assetDirectory, 0777, true);
}


echo "Fetching avatar..." . PHP_EOL;


$avatarUrl = "https://avatar.roblox.com/v2/avatar/users/" . $userId . "/avatar";

$curl = curl_init($avatarUrl);

curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);

$response = curl_exec($curl);

if ($response === false) {
    die("cURL error: " . curl_error($curl) . PHP_EOL);
}

$statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

curl_close($curl);

if ($statusCode !== 200) {
    die("HTTP error: " . $statusCode . PHP_EOL . $response);
}


$avatar = json_decode($response, true);

if (!is_array($avatar)) {
    die("Failed to decode avatar JSON." . PHP_EOL);
}


file_put_contents(
    $avatarPath,
    json_encode($avatar, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

echo "Saved avatar: " . $avatarPath . PHP_EOL;


foreach (glob($assetDirectory . "/*") as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}


$appearanceFiles = [];
$rccAssetFiles = [];


echo PHP_EOL;
echo "Downloading supported assets..." . PHP_EOL;


foreach ($avatar["assets"] ?? [] as $asset) {

    $assetId = $asset["id"] ?? null;
    $versionId = $asset["currentVersionId"] ?? null;
    $name = $asset["name"] ?? "Unknown";
    $assetType = $asset["assetType"]["name"] ?? "Unknown";

    if (!$assetId || !$versionId) {
        continue;
    }


    if (
        $assetType !== "Shirt" &&
        $assetType !== "Pants" &&
        $assetType !== "Hat"
    ) {
        echo "Skipping: " . $name . " (" . $assetType . ")" . PHP_EOL;
        continue;
    }


    echo "Downloading: " . $name . " (" . $assetType . ")" . PHP_EOL;


    $assetUrl =
        "https://assetdelivery.roblox.com/v1/assetId/"
        . $assetId
        . "/version/"
        . $versionId;


    $curl = curl_init($assetUrl);

    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);

    $assetData = curl_exec($curl);

    if ($assetData === false) {
        echo "  Version download failed: " . curl_error($curl) . PHP_EOL;
        curl_close($curl);
        $assetData = false;
    } else {
        $assetStatus = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if (
            $assetStatus !== 200 ||
            (
                str_starts_with(trim($assetData), "{") &&
                str_contains($assetData, '"errors"')
            )
        ) {
            echo "  Version download failed (HTTP " . $assetStatus . "), trying asset ID..." . PHP_EOL;
            $assetData = false;
        }
    }


    if ($assetData === false) {

        $assetUrl =
            "https://assetdelivery.roblox.com/v1/assetId/"
            . $assetId;


        $curl = curl_init($assetUrl);

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);

        $assetData = curl_exec($curl);

        if ($assetData === false) {
            echo "  Asset ID download failed: " . curl_error($curl) . PHP_EOL;
            curl_close($curl);
            continue;
        }

        $assetStatus = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        curl_close($curl);


        if ($assetStatus !== 200) {
            echo "  Asset ID HTTP error: " . $assetStatus . PHP_EOL;
            continue;
        }


        if (
            str_starts_with(trim($assetData), "{") &&
            str_contains($assetData, '"errors"')
        ) {
            echo "  Asset ID returned an invalid response." . PHP_EOL;
            continue;
        }
    }


    $assetFileName = $assetId . "_" . $versionId . ".rbxm";

    $assetPath = $assetDirectory . "/" . $assetFileName;

    file_put_contents($assetPath, $assetData);


    $rccAssetPath = $rccContentDirectory . "/" . $assetFileName;

    if (!copy($assetPath, $rccAssetPath)) {
        echo "  Failed to copy asset into RCC content." . PHP_EOL;
        continue;
    }


    $rccAssetFiles[] = $rccAssetPath;
    $appearanceFiles[] = "rbxasset://" . $assetFileName;


    echo "  Saved." . PHP_EOL;
}


if (count($appearanceFiles) === 0) {
    die("No compatible avatar assets were downloaded." . PHP_EOL);
}


echo PHP_EOL;
echo "Assets prepared: " . count($appearanceFiles) . PHP_EOL;


$characterAppearance = implode(";", $appearanceFiles);

echo "CharacterAppearance:" . PHP_EOL;
echo $characterAppearance . PHP_EOL;


echo PHP_EOL;
echo "Starting RCC..." . PHP_EOL;


$rcc = new RCCServiceSoap("127.0.0.1", 64989);

$job = new Job("AvatarRender_" . $userId);


$characterAppearanceLua = json_encode($characterAppearance);


$scriptText = <<<LUA
game:GetService("ContentProvider"):SetBaseUrl("http://www.roblox.com")

game:GetService("ScriptContext").ScriptsDisabled = true


local Players = game:GetService("Players")

local player = Players:CreateLocalPlayer($userId)

player.CharacterAppearance = $characterAppearanceLua

print("CharacterAppearance:", player.CharacterAppearance)

player:LoadCharacter()

print("Character loaded:", player.Character)


local image = game:GetService("ThumbnailGenerator"):Click(
	"PNG",
	500,
	500,
	true
)

return image
LUA;


$script = new ScriptExecution(
    "AvatarRender_" . $userId,
    $scriptText
);


$result = $rcc->BatchJob($job, $script);


if (is_soap_fault($result)) {
    echo "SOAP ERROR" . PHP_EOL;
    var_dump($result);
    exit;
}


if (!$result) {
    die("RCC returned an empty result." . PHP_EOL);
}


$imageData = base64_decode($result);

if ($imageData === false) {
    die("Failed to decode image." . PHP_EOL);
}


file_put_contents($outputPath, $imageData);


echo PHP_EOL;
echo "Render complete!" . PHP_EOL;
echo "Saved: " . $outputPath . PHP_EOL;


foreach ($rccAssetFiles as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}