<?php

/**
 * @package Amazon Affiliate Addon for ElkArte
 * @license MPL 1.1 http://mozilla.org/MPL/1.1/
 *
 * Sets default values for the new options, without touching existing ones
 * (the affiliate ID of a previous installation is kept).
 */

if (file_exists(__DIR__ . '/SSI.php') && !defined('ELK'))
{
	require_once(__DIR__ . '/SSI.php');
}
elseif (!defined('ELK'))
{
	die('<b>Error:</b> Cannot install - please verify you put this in the same place as ElkArte\'s index.php.');
}

global $modSettings;

$defaults = array(
	'amazon_affiliate_replace_existing' => 1,
	'amazon_affiliate_resolve_short' => 1,
	'amazon_affiliate_icon' => 1,
);

$changes = array();
foreach ($defaults as $key => $value)
{
	if (!isset($modSettings[$key]))
	{
		$changes[$key] = $value;
	}
}

if (!empty($changes))
{
	updateSettings($changes);
}
