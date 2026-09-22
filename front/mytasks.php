<?php
include('../../../inc/includes.php');
Session::checkLoginUser();
Html::redirect(PLUGIN_PROJECTFLOW_WEBDIR . '/front/tasks.php?scope=mine');
