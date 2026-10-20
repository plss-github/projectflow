<?php

use GlpiPlugin\Projectflow\RuleProjectTaskCollection;

Session::checkLoginUser();

$rulecollection = new RuleProjectTaskCollection();

include(GLPI_ROOT . '/front/rule.common.php');
