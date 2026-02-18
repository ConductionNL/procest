<?php

use OCP\Util;

$appId = OCA\Procest\AppInfo\Application::APP_ID;
Util::addScript($appId, $appId . '-main');
?>
<div id="content"></div>
