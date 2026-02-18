<?php

use OCP\Util;

$appId = OCA\Procest\AppInfo\Application::APP_ID;
Util::addScript($appId, $appId . '-settings');
?>
<div id="procest-settings"></div>
