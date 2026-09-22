<?php

// fix so rcc doesnt return empty by fixing the lua.

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

if (!is_dir($rccContentDirectory)) {
    mkdir($rccContentDirectory, 0777, true);
}


echo "Fetching avatar..." . PHP_EOL;

$avatarUrl = "https://avatar.roblox.com/v2/avatar/users/" . $userId . "/avatar";

$curl = curl_init($avatarUrl);

curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($curl, CURLOPT_ENCODING, "");

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
$resolvedAssets = [];


function downloadAsset($assetId, $versionId = null) {
    global $resolvedAssets;

    $cacheKey = $assetId . ":" . ($versionId ?? "");

    if (isset($resolvedAssets[$cacheKey])) {
        return $resolvedAssets[$cacheKey];
    }

    $urls = [];

    if ($versionId) {
        $urls[] =
            "https://assetdelivery.roblox.com/v1/assetId/"
            . $assetId
            . "/version/"
            . $versionId;
    }

    $urls[] =
        "https://assetdelivery.roblox.com/v1/assetId/"
        . $assetId;


    foreach ($urls as $assetUrl) {
        $curl = curl_init($assetUrl);

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_ENCODING, "");

        $data = curl_exec($curl);

        if ($data === false) {
            curl_close($curl);
            continue;
        }

        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        curl_close($curl);

        if ($status !== 200) {
            continue;
        }


        $json = json_decode($data, true);

        if (
            is_array($json) &&
            isset($json["location"])
        ) {
            $location = $json["location"];

            $curl = curl_init($location);

            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($curl, CURLOPT_ENCODING, "");

            $data = curl_exec($curl);

            if ($data === false) {
                curl_close($curl);
                continue;
            }

            $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

            curl_close($curl);

            if ($status !== 200) {
                continue;
            }
        }


        if (
            str_starts_with(trim($data), "{") &&
            str_contains($data, '"errors"')
        ) {
            continue;
        }


        $resolvedAssets[$cacheKey] = $data;

        return $data;
    }


    return false;
}


function resolveAssetDependencies(&$data, $sourceName) {
    global $assetDirectory;
    global $rccContentDirectory;
    global $rccAssetFiles;


    if (
        !str_contains($data, "roblox.com/asset") &&
        !str_contains($data, "rbxassetid://")
    ) {
        return $data;
    }


    preg_match_all(
        '/(?:https?:\/\/[^<"\']*roblox\.com\/asset\/?\?id=|rbxassetid:\/\/)(\d+)/i',
        $data,
        $matches
    );

    if (empty($matches[1])) {
        return $data;
    }


    $assetIds = array_unique($matches[1]);


    foreach ($assetIds as $assetId) {
        echo "  Resolving dependency: " . $assetId . PHP_EOL;

        $dependencyData = downloadAsset($assetId);

        if ($dependencyData === false) {
            echo "  Failed to download dependency: " . $assetId . PHP_EOL;
            continue;
        }


        $dependencyFileName = $assetId;

        $dependencyPath =
            $assetDirectory . "/" . $dependencyFileName;

        $rccDependencyPath =
            $rccContentDirectory . "/" . $dependencyFileName;


        $dependencyData =
            resolveAssetDependencies(
                $dependencyData,
                $dependencyFileName
            );


        file_put_contents(
            $dependencyPath,
            $dependencyData
        );


        if (!copy($dependencyPath, $rccDependencyPath)) {
            echo "  Failed to copy dependency into RCC content." . PHP_EOL;
            continue;
        }


        $rccAssetFiles[] = $rccDependencyPath;


        $data = preg_replace(
            '/https?:\/\/[^<"\']*roblox\.com\/asset\/?\?id=' . preg_quote($assetId, '/') . '/i',
            'rbxasset://' . $dependencyFileName,
            $data
        );

        $data = str_replace(
            'rbxassetid://' . $assetId,
            'rbxasset://' . $dependencyFileName,
            $data
        );
    }


    return $data;
}


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


	// 'Whitelisted' assets.
    if (
        $assetType !== "Shirt" &&
        $assetType !== "Pants" &&
        $assetType !== "Hat" &&
        $assetType !== "LeftArm" &&
        $assetType !== "LeftLeg" &&
        $assetType !== "RightArm" &&
        $assetType !== "RightLeg" &&
        $assetType !== "Torso"
    ) {
        echo "Skipping: " . $name . " (" . $assetType . ")" . PHP_EOL;
        continue;
    }


    echo "Downloading: " . $name . " (" . $assetType . ")" . PHP_EOL;

    $assetData = downloadAsset($assetId, $versionId);

    if ($assetData === false) {
        echo "  Failed to download asset." . PHP_EOL;
        continue;
    }


    $assetData =
        resolveAssetDependencies(
            $assetData,
            $assetId . "_" . $versionId
        );


    $xmlFileName = $assetId . "_" . $versionId . ".rbxmx";
    $xmlPath = $assetDirectory . "/" . $xmlFileName;

    file_put_contents(
        $xmlPath,
        $assetData
    );


    $rccAssetPath =
        $rccContentDirectory . "/" . $xmlFileName;


    if (!copy($xmlPath, $rccAssetPath)) {
        echo "  Failed to copy asset into RCC content." . PHP_EOL;
        continue;
    }


    $rccAssetFiles[] = $rccAssetPath;


    if (
        $assetType === "Pants" ||
        $assetType === "Shirt" ||
        $assetType === "Hat"
    ) {
        $appearanceFiles[] = "rbxasset://" . $xmlFileName;
    }


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


$wearableFiles = [];


foreach ($avatar["assets"] ?? [] as $asset) {
    $assetId = $asset["id"] ?? null;
    $versionId = $asset["currentVersionId"] ?? null;
    $assetType = $asset["assetType"]["name"] ?? "Unknown";

    if (!$assetId || !$versionId) {
        continue;
    }


    if (
        $assetType === "Pants" ||
        $assetType === "Shirt" ||
        $assetType === "Hat"
    ) {
        $wearableFiles[] =
            "rbxasset://" . $assetId . "_" . $versionId . ".rbxmx";
    }
}


$wearableFilesLua = json_encode($wearableFiles);


$scriptText = '
print("RCC SCRIPT STARTED")

game:GetService("ContentProvider"):SetBaseUrl("http://www.roblox.com")
game:GetService("ScriptContext").ScriptsDisabled = true

local Players = game:GetService("Players")
local ThumbnailGenerator = game:GetService("ThumbnailGenerator")

local player = Players:CreateLocalPlayer(1)
player:LoadCharacter()
local character = player.Character

print("Character:", character)

print("BEFORE GETOBJECTS")

local appearanceFiles = {
    "rbxasset://301811432_10151325111.rbxmx", --pants
    "rbxasset://607785314_955993454.rbxmx", --shirt
	"rbxasset://607702162_1171146069.rbxmx" -- left arm or hat?
}

for _, assetFile in pairs(appearanceFiles) do
    print("Loading:", assetFile)

    local objects = game:GetObjects(assetFile)

    print("Object count:", #objects)

    for _, object in pairs(objects) do
        print("Object:", object.ClassName, object.Name)

        object.Parent = character
    end
end

print("AFTER GETOBJECTS")

print("BEFORE THUMBNAIL")

local image = ThumbnailGenerator:Click("PNG",500,500,true)

print("AFTER THUMBNAIL")

return image
';


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
    echo "RCC returned an empty result." . PHP_EOL;

    exit;
}


$imageData = base64_decode($result);
if ($imageData === false) {
    die("Failed to decode image." . PHP_EOL);
}


file_put_contents($outputPath, $imageData);


echo PHP_EOL;
echo "Render complete!!" . PHP_EOL;
echo "Saved: " . $outputPath . PHP_EOL;


foreach ($rccAssetFiles as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}