<?php

$RccPath = __DIR__ . "/PHP-RCCServiceSoap/Assemblies/Roblox/Grid/Rcc/";

require_once $RccPath . "Job.php";
require_once $RccPath . "LuaType.php";
require_once $RccPath . "LuaValue.php";
require_once $RccPath . "ScriptExecution.php";
require_once $RccPath . "Status.php";
require_once $RccPath . "RCCServiceSoap.php";

use Roblox\Grid\Rcc\RCCServiceSoap;
use Roblox\Grid\Rcc\Job;
use Roblox\Grid\Rcc\ScriptExecution;

$RCCServiceSoap = new RCCServiceSoap("127.0.0.1", 64989);

echo "HelloWorld: " . ($RCCServiceSoap->HelloWorld() ?? "Failed!") . PHP_EOL;
echo "Version: " . ($RCCServiceSoap->GetVersion() ?? "Failed!") . PHP_EOL;

$status = $RCCServiceSoap->GetStatus();

echo "Environment count: " . ($status->environmentCount ?? "Failed!") . PHP_EOL;

$job = new Job("TestJob");

$scriptText = '
print("Hello from RCC!")
return "RCC_TEST_SUCCESS"
';

$script = new ScriptExecution("TestJob-Script", $scriptText);

$result = $RCCServiceSoap->BatchJob($job, $script);

echo "BatchJob result: ";

if (is_soap_fault($result)) {
    echo "SOAP ERROR" . PHP_EOL;
} else {
    var_dump($result);
}