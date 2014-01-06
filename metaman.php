<?php
/**
 * MetaMan setup script.
 *
 * Registers extension details and the used hooks.
 *
 * @author Timo Taglieber <mail@timotaglieber.de>
 * @version 0.2.4
 *
 * This work is licensed under the 	Creative Commons Attribution 3.0 Unported License:
 * http://creativecommons.org/licenses/by/3.0/
 */

# Alert the user that this is not a valid entry point to MediaWiki if they try
# to access the special pages file directly.
if (!defined('MEDIAWIKI')) {
	echo "Sorry, calling metaman.php is not a valid entrypoint to MediaWiki.";
	exit(1);
}

# Register extension credits
$wgExtensionCredits['other'][] = array(
	'path' 			 => __FILE__,
	'name' 			 => 'MetaMan',
    'description' 	 => 'Support for user-friendly metadata management.',
	'descriptionmsg' => "credits_desc",
	'version' 		 => '0.2.4',
    'author' 		 => 'Timo Taglieber',
    'url' 			 => 'http://www.mediawiki.org/wiki/Extension:MetaMan');

# Tell MediaWiki to load the extension body.
$wgAutoloadClasses['MetaMan'] = dirname(__FILE__).'/metaman.body.php';

# Load internationalization file
$wgExtensionMessagesFiles['MetaMan'] = dirname(__FILE__).'/metaman.i18n.php';

# Load alias file
$wgExtensionAliasesFiles['MetaMan'] = dirname(__FILE__).'/metaman.alias.php';

# Let MediaWiki know about your new special page.
$wgSpecialPages['MetaMan'] = 'MetaMan';

# Register function to be called with EditPage hook
$wgHooks['EditPage::showEditForm:initial'][] = 'MetaMan::composeMenu';

# Register function to be called when a page was deleted
$wgHooks['ArticleDeleteComplete'][] = 'MetaMan::removeDeletedPage';

# Register function for use via Ajax
$wgAjaxExportList[] = "MetaMan::getSuggestions";