<?php
/**
 * MetaMan main script.
 * 
 * Compose the MetaMan menu with a list of a page's current categories and
 * Semantic Media Wiki properties. Retrieve suggestions for those metadata
 * through an AJAX-call (Javascript) from within the EditPage to his script.
 *
 * @author Timo Taglieber <mail@timotaglieber.de>
 * @version 0.2.1
 *
 * This work is licensed under the 	Creative Commons Attribution 3.0 Unported License:
 * http://creativecommons.org/licenses/by/3.0/
 */


# Increase time limit. Building the similarity matrix may take longer than the
# default 30 seconds.
set_time_limit(60);



/**
 * This class holds several static functions used for the MetaMan menu.
 * It also generates output for the special page "Special:MetaMan".
 */
class MetaMan extends SpecialPage {
	/** Read access to database backend. */
	public static $dbr = null;
	
	/** Write access to database backend. */
	public static $dbw = null;
	
	/** Array of stopwords to exclude from term vectorization. */
	public static $stopwords = null;
	
	/** 
	 * Limit number of similar pages to be extracted from the similarity 
	 * matrix by providing a minimal similarity value as threshold.
	 */
	public static $simPagesThreshold = 0;
	
	/** Switch output of debug information on/off */
	public static $debugMode = true;
	
	/**  Large string with debug info, gets appended during execution. */
	public static $debugInfo = '';
	
	
	/** 
	 * Call parent constructor and load internationalization messages. 
	 */
	function __construct() {
		parent::__construct('MetaMan');
		wfLoadExtensionMessages('MetaMan');
	}	
 


	/** 
	 * Compose output of special page. 
	 */
	function execute($par) {
		global $wgOut;
 		$this->setHeaders();				
		$wgOut->addHTML(wfMsg('specialpage'));
	}
	
	
	/**
	 * Return the string with debugging information (or an emtpy string).
	 */
	public static function getDebugInfo() {
		if (MetaMan::$debugMode) {
			return MetaMan::$debugInfo;
		} else {
			return '';
		}
	}
	
	
	
	/** 
	 * Get the base directory for this extension 
	 */
	public static function getBaseDir() {
		return dirname(__FILE__).'/';
	}
	
	
	
	/** 
	 * Get the translation for the namespace "Category" in the wiki's language.
	 */
	public static function getCategoryNsTransl() {
		# $wgLang - Language object selected by user preferences
		# $wgContLang - Language object associated with the wiki being viewed.
		global $wgContLang;
		return $wgContLang->namespaceNames[NS_CATEGORY];
	}
	
	
	/**
	 * Remove punctuation and other characters from a given string.
	 */
	public static function removePunctuation($text) {
		$urlBrckts    = '\[\]\(\)';
		$urlSpaceBef = ':;\'_\*%@&?!' . $urlBrckts;
		$urlSpaceAf  = '\.,:;\'\-_\*@&\/\\\\\?!#' . $urlBrckts;
		$urlAll         = '\.,:;\'\-_\*%@&\/\\\\\?!#' . $urlBrckts;
		$specialQuts = '\'"\*<>';
		$fullStp      = '\x{002E}\x{FE52}\x{FF0E}';
		$cm         = '\x{002C}\x{FE50}\x{FF0C}';
		$arabSp       = '\x{066B}\x{066C}';
		$numSeprs = $fullStp . $cm . $arabSp;
		$numberSgn    = '\x{0023}\x{FE5F}\x{FF03}';
		$percnt       = '\x{066A}\x{0025}\x{066A}\x{FE6A}\x{FF05}\x{2030}\x{2031}';
		$prme         = '\x{2032}\x{2033}\x{2034}\x{2057}';
		$numModf  = $numberSgn . $percnt . $prme;

		return preg_replace(
			array(
				'/[\p{Z}\p{Cc}\p{Cf}\p{Cs}\p{Pi}\p{Pf}]/u',
				'/\p{Po}(?<![' . $specialQuts . $numSeprs . $urlAll . $numModf . '])/u',
				'/[\p{Ps}\p{Pe}](?<![' . $urlBrckts . '])/u',
				'/[' . $specialQuts . $numSeprs . $urlSpaceAf . '\p{Pd}\p{Pc}]+((?= )|$)/u',
				'/((?<= )|^)[' . $specialQuts . $urlSpaceBef . '\p{Pc}]+/u',
				'/((?<= )|^)\p{Pd}+(?![\p{N}\p{Sc}])/u',
				'/ +/',
			), 
			' ', $text);
	}
	
	
	
	/**
	 * Enable database read/write access, create similarity table if necessary.
	 */
	public static function setupDatabase() {
		MetaMan::$dbr = wfGetDB(DB_SLAVE);
		MetaMan::$dbw = wfGetDB(DB_MASTER);
		MetaMan::createSimTable();
		MetaMan::createRevTable();
		return true;
	}



	/** 
	 * Wrapper for Database::select(). Returns all resulting rows.
	 */
	public static function select($tables, $cols, $where) {
		$rows = array();
		$res = MetaMan::$dbr->select($tables, $cols, $where);
		while ($row = MetaMan::$dbr->fetchObject($res)) {
			$rows[] = $row;
		}
		MetaMan::$dbr->freeResult($res);
		return $rows;
	}



	/** 
	 * Wrapper for Database::update(). Returns all resulting rows.
	 */
	public static function update($tables, $values, $where) {
		$res = MetaMan::$dbw->update($tables, $values, $where);
		MetaMan::$dbw->commit();
		return $res;
	}



	/** 
	 * Wrapper for Database::insert(). Returns all resulting rows.
	 */
	public static function insert($table, $values) {
		$res = MetaMan::$dbw->insert($table, $values);
		MetaMan::$dbw->commit();
		return $res;
	}



	/**
	 * Get the page's title from page_id.
	 */
	public static function getPageTitle($pageID) {
		$rows = MetaMan::select('page', 'page_title', 'page_id='.$pageID);
		return $rows[0]->page_title; 
	}



	/**
	 * Get the page's text content from page_id.
	 */
	public static function getWikiText($pageID) {
		$textRows = MetaMan::select(array('page', 'revision', 'text'),
			'old_text', 'page_id = '.$pageID.' and page_latest = rev_id '.
			'and rev_text_id = old_id');
		$text = '';
		foreach ($textRows as $row) { 
			$text = sprintf('%s', $row->old_text);		
		}
		return $text;	
	}



	/**
	 *  Insert or update the computed similiarity of two pages in the table
	 *  pagesim.
	 */
	public static function storeSim($id1, $id2, $sim) {
		$lastComparison = MetaMan::select(
			'metaman_pagesim', '*', 'id1='.$id1.' and id2='.$id2);
		if (count($lastComparison) == 1) {
			MetaMan::update('metaman_pagesim', 
				array('sim='.$sim), 
				array('id1='.$id1.' and id2='.$id2));
			MetaMan::$debugInfo .= ' (update done)<br />';
		} else {
			MetaMan::insert('metaman_pagesim', array(
				'id1' => $id1, 
				'id2' => $id2, 
				'sim' => $sim));
			MetaMan::$debugInfo .= ' (inserted)<br />';
		}
	}



	/**
	 * Store the revision of a page included in the similarity matrix.
	 */
	public static function storePageRev($id, $rev) {
		MetaMan::$debugInfo .= 'Checking revision id for pageID='.$id.' ('.
			MetaMan::getPageTitle($id).')<br />';
		$lastRev = MetaMan::select('metaman_pagerev', 'rev', 'id='.$id);
		if (count($lastRev) == 1) { # $id exists in table
			MetaMan::$debugInfo .= 'rev='.$rev.', rev_in_pagesim='.
				$lastRev[0]->rev;
			if ($rev == $lastRev[0]->rev) { # $rev is equal, thus up to date
				MetaMan::$debugInfo .= ' (up to date)<br />';
				return;
			} else { # $rev differs, update in table
				MetaMan::update(
					'metaman_pagerev', array('rev='.$rev), array('id='.$id));
				MetaMan::$debugInfo .= ' (update done)<br />';
			}
		} else {
			MetaMan::insert('metaman_pagerev', array(
				'id' => $id,
				'rev' => $rev));
			MetaMan::$debugInfo .= 'rev='.$rev.' (inserted)';
		}	
	}



	/**
	 * Check if page's revision has changed and metaman_pagerev needs to be
	 * updated.
	 */
	public static function pageRevUpToDate($id, $rev) {
		$lastRev = MetaMan::select('metaman_pagerev', 'rev', 'id='.$id);
		if (count($lastRev) == 1 and $rev == $lastRev[0]->rev) {
			return true;
		} else {
			MetaMan::$debugInfo .= '<p>id='.$id.' with rev='.$rev.
				' is not in metaman_pagerev or is not up to date.</p>';
			return false;
		}
	}



	/**
	 * Retrieve all pages with their latest revisions and text_ids.
	 */
	public static function getLatestPageRevisions() {
		$pageRevisionRows = MetaMan::select(array('page', 'revision'),
			'page_id, page_latest, page_title, rev_text_id', 
			'page_latest = rev_id');
		$pageRevisions = array();
		foreach ($pageRevisionRows as $row) {
			$id = sprintf('%u', $row->page_id);
			if ($id == "1") {
				continue; # Exclude the wiki's main page
				# assume that the main page always has page_id 1 in table
				# "page".
			}
			$rev = sprintf('%u', $row->page_latest); 
			$title = sprintf('%s', $row->page_title);
			$textid = sprintf('%u', $row->rev_text_id);

			# Exclude certain non-content pages
			if ($title == 'Common.js') {
				continue;
			}
			$pageRevisions[] = array($id, $rev, $title, $textid);		
		}
		return $pageRevisions;
	}



	/**
	 * Create the table containing the page similarity in the DB.
	 */
	public static function createSimTable() {
		$tableName = MetaMan::$dbw->tableName('metaman_pagesim');
		$queryStr = <<<QUERYSTR
create table $tableName (
	id1 integer(10) unsigned not null references page(page_id),
	id2 integer(10) unsigned not null references page(page_id),
	sim float not null,
	primary key(id1, id2)
) engine = InnoDB, default charset = binary;
QUERYSTR;
		if (!MetaMan::$dbw->tableExists('metaman_pagesim')) { 
			# if the table doesn't exist
			if (MetaMan::$dbw->query($queryStr)) {
				MetaMan::$dbw->commit();
				return true; # table successfully created
			} else {
				return false; # table creation failed
			}
		} else {
			# assume table already exists and has the correct schema
			return true; 
		}		
	}



	/**
	 * Drop the table containing page the similarity from the DB.
	 * This function is not used so far (Version 0.2).
	 */
	public static function dropSimTable() {
		if (MetaMan::$dbw->tableExists('metaman_pagesim')) { 
			# if the table exists
			if (MetaMan::$dbw->query('drop table metaman_pagesim')) {
				MetaMan::$dbw->commit();
				return true; # table successfully deleted
			} else {
				return false; # failed to drop table
			}
		} else {
			return true; # assume table doesn't exist, which is desired
		}		
	}



	/**
	 * Create the table containing the latest page revisions.
	 */
	public static function createRevTable() {
		$tableName = MetaMan::$dbw->tableName('metaman_pagerev');
		$queryStr = <<<QUERYSTR
create table $tableName (
	id integer(10) unsigned not null,
	rev integer(10) unsigned not null,
	foreign key(id) references page(page_id) on delete cascade
) engine = InnoDB, default charset = binary;
QUERYSTR;
	#references revision(rev_id)
		if (!MetaMan::$dbw->tableExists('metaman_pagerev')) { 
			# if the table doesn't exist
			if (MetaMan::$dbw->query($queryStr)) {
				MetaMan::$dbw->commit();
				return true; # table successfully created
			} else {
				return false; # table creation failed
			}
		} else {
			return true; # assume table already exists and has the correct
			# schema
		}		
	}



	/**
	 * Drop the table metaman_pagerev from the DB.
	 * This function is not used so far (Version 0.2).
	 */
	public static function dropRevTable() {
		if (MetaMan::$dbw->tableExists('metaman_pagerev')) { 
			# if the table exists
			if (MetaMan::$dbw->query('drop table metaman_pagerev')) {
				MetaMan::$dbw->commit();
				return true; # table successfully deleted
			} else {
				return false; # failed to drop table
			}
		} else {
			return true; # assume table doesn't exist, which is desired
		}		
	}



	/**
	 * Get the page's text from text_id.
	 */
	public static function getPageText($textID) {
		$textRows = MetaMan::select('text', 'old_text as text', 'old_id='.$textID);
		return $textRows[0]->text; # assume there's only one value!
	}



	/**
	 * Remove a deleted page from the similarity matrix (metaman_pagesim).
	 * This is triggered by the hook ArticleDeleteComplete.
	 */
	public static function removeDeletedPage($article, $user, $reason, $id) {		
		try {
		    wfGetDB(DB_MASTER)->delete(
				'metaman_pagesim', array('id1='.$id.' or id2='.$id));
		} catch (Exception $e) {
			echo $e->getMessage();
			echo ' in '.$e->getFile().', line: '.$e->getLine().'.';
			return false;
			# TODO: More Error handling goes here
		}		
		return true;
	}	


	
	/**
	 * Read a file and return its content.
	 */
	public static function getFileContent($fname) {
		if (is_file($fname) and is_readable($fname)) {
			return file_get_contents($fname);
		} else {
			# file doesn't exist or is not readable
			return 'ERROR: could not read '.$fname;
		}		
	}



	/**
	 * Reverse the key/value order of an array.
	 */
	public static function invertAssocArray($a) {
		$invArr = array();
		foreach ($a as $key => $val) {
			$invArr[] = array($val, $key); 
		}
		return $invArr;
	}



	/**
	 * Parse all categories listed in a page's text.
	 */
	public static function getCategories($text) {
		$pattern = '/\[\[((Category)|('.
			MetaMan::getCategoryNsTransl().')):([^:\]]+)\]\]/';
		$matches = array();
		$categories = array();
		if (preg_match_all($pattern, $text, $matches) >= 1) {
			# at least one match found
			foreach ($matches[4] as $match) {
				$categories[] = $match;
			}
		}	
		return $categories;
	}
	
	
	
	/**
	 * Parse all wiki template parameters contained in a page's text.
	 * These items are currently treated as SMW properties until specific
	 * handling is implemented.
	 */
	public static function getTemplateProperties($text) {
		# Example: 
		# {{Some text here
		#	|Name=Value
		#	|AnotherName=AnotherValue
		# }}
		
		# one group in this pattern: the whole content of {{...}}
		$outerPattern = '/\{\{([^}]+)\}\}/'; 

		# two groups in this pattern: name and value
		$innerPattern = '/\|([^=|}]+)=([^=|}]+)/'; 
		
		$outerMatches = array();
		$innerMatches = array();
		$properties = array();
		if (preg_match_all($outerPattern, $text, $outerMatches) >= 1) {
			# at least one match found
			foreach ($outerMatches[1] as $match) {
				# Parse content of each {{...}} item
				if (preg_match_all($innerPattern, $match, $innerMatches) >= 1) {
					for ($i = 0; $i < count($innerMatches[1]); $i += 1) {
						$properties[] = array(
							$innerMatches[1][$i], $innerMatches[2][$i]);
					}
				}
			}	
		}
		
		return $properties;
	}



	/**
	 * Parse all Semantic Media Wiki properties contained in a page's text.
	 * Makes a subcall to getTemplateProperties() to get the properties in wiki
	 * templates as well.
	 */
	public static function getProperties($text) {
		$pattern = '/\[\[([^:]+::[^\]]+)\]\]/';
		$delim = '::';
		$matches = array();
		$properties = array();
		if (preg_match_all($pattern, $text, $matches) >= 1) {
			# at least one match found
			foreach ($matches[1] as $match) {
				$items = explode($delim, $match);
				$value = $items[count($items) - 1];
				$propMatches = array_slice($items, 0, -1);
				foreach ($propMatches as $property) {
					$properties[] = array($property, $value);
				}
			}				
		} 
		# Now get the template properties as well
		$templProps = MetaMan::getTemplateProperties($text);
		return $properties + $templProps;
	}



	/**
	 * Count the occurrences of all categories in all the pages similar to
	 * $pageID.
 	 */
	/* OUTDATED!
	public static function countCats($simpages, $pageID) {
		$catFreq = array();	
		foreach ($simpages as $row) {
			foreach (MetaMan::getCategories($pageID) as $category) {
					if (array_key_exists($category, $catFreq)) {
						$catFreq[$category] += 1;
					} else {
						$catFreq[$category] = 1;
					}			
			}
		}
		return $catFreq;	
	}
	*/



	/**
	 * Get all pages from the similarity matrix that have been compared with
	 * $pageID and have a similarity above the threshold.
	 */
	public static function getSimPages($pageID, $threshold) {
		$qry = MetaMan::select('metaman_pagesim', 'id1, id2, sim',
			'sim > '.$threshold.' and '.
			'(id1='.$pageID.' or id2='.$pageID.') '.
			'order by sim desc');
		$simpages = array();
		foreach ($qry as $row) {
			if ($pageID == $row->id1) {
				$partnerID = $row->id2;
			} else {
				$partnerID = $row->id1;
			}
			$simpages[] = array($partnerID, $row->sim);		
		}
		return $simpages;
	}



	/**
	 * Rank the $limit most important categories or properties, based on
	 * occurrence in the similar pages and the according similarity of each page
	 * (to the current page).
	 */
	public static function getRanking($pageItems, $limit) {
		$ranking = array();
		foreach ($pageItems as $pageitem) {
			$sim = $pageitem[0];
			$items = $pageitem[1];		
			foreach ($items as $item) {			
				if (array_key_exists($item, $ranking)) {
					$ranking[$item] += $sim; # 1;
				} else {
					$ranking[$item] = $sim; # 1;
				}							
			}		
		} 	
		$ranking = MetaMan::invertAssocArray($ranking); # invert key-value pairs
		asort($ranking); # sort by item frequency
		$ranking = array_reverse($ranking); # Reverse order from asc. to desc.
		$ranking = array_slice($ranking, 0, $limit); # remove surplus items
		return $ranking;
	}



	/**
	 * Insert a category name into the corresponding MediaWiki syntax.
	 */
	public static function getCategoryCode($category) {
		return '[['.MetaMan::getCategoryNsTransl().':'.$category.']]';
	}



	/**
	 * Compile the list of category suggestions based on their importance
	 * ranking.
	 */
	public static function getCategorySuggestions($categories) {
		# Only list the $limit most used categories in all similiar pages
		$limit = 10; 
		$suggestions = array();
		foreach (MetaMan::getRanking($categories, $limit) as $rankedCategory) {
			$score = $rankedCategory[0];
			$category = $rankedCategory[1];
			MetaMan::$debugInfo .= '<p>Suggested Category: '.$category.
				' (score='.$score.')</p>';
			$suggestions[] = array(
				MetaMan::getCategoryCode($category), $category);  
		}
		return $suggestions;
	}



	/**
	 * Insert a property and its value into the corresponding Semantic Media
	 * Wiki syntax.
	 */
	public static function getPropertyCode($property, $value) {
		return '[['.$property.'::'.$value.']]';
	}



	/**
	 * Compile the list of property suggestions based on their importance
	 * ranking.
	 */
	public static function getPropertySuggestions($properties) {
		# Only list the $limit most used properties in all similiar pages
		$limit = 10;	
		$values = array();
		$rankingList = array();
		foreach ($properties as $property) {
			$sim = $property[0];
			$propertyList = $property[1];
			$props = array();
			foreach ($propertyList as $listItem) {
				$props[] = $listItem[0];
				$values[$listItem[0]] = $listItem[1];
			}
			$rankingList[] = array($sim, $props);
		}
	
		$suggestions = array();
		foreach (MetaMan::getRanking($rankingList, $limit) as $rankedProperty) {
			$score = $rankedProperty[0];
			$property = $rankedProperty[1];
			$value = $values[$property];
			$suggestions[] = array(
				'code' => MetaMan::getPropertyCode($property, $value),
				'properties' => array($property),
				'value' => $value);
		}
		return $suggestions;
	}



	/**
	 * Construct term vector from page's text content. At first, punctuation
	 * gets stripped and stopwords removed, then remaining tokens will be
	 * counted.
	 */
	public static function getTermVector($text) {	
		$text = MetaMan::removePunctuation($text);
		$text = strtolower($text); # Convert everything to lowercase
	
		# Tokenize text
		$pattern = ' .,;:-=+#?!()[]{}|*"/<>';
		$token = strtok($text, $pattern);
		$tokens = array();	
		while($token !== false) {
			$tokens[] = $token;
			$token = strtok($pattern);
		}

		# Count tokens and filter out stopwords
		$termVec = array();
		foreach ($tokens as $token) {
			if (!array_key_exists($token, MetaMan::$stopwords)) {
				if (array_key_exists($token, $termVec)) {
					$termVec[$token] += 1;
				} else {
					$termVec[$token] = 1;
				}			
			}	
		}	

		MetaMan::$debugInfo .= 
			'<p style="font-size:0.7em; border:1px solid red">';
		foreach ($termVec as $term => $freq) {
			MetaMan::$debugInfo .= 
				'<span style="background-color:rgb(230,230,230); '.
				'padding-right:5px">'.$term.'('.$freq.')</span> ';		
		}
		MetaMan::$debugInfo .= '</p>';
	
		return $termVec;
	}



	/**
	 * Sum up the frequencies of all terms in a page's term vector.
	 */
	public static function getFreqSum($vec) {
		$sum = 0;
		foreach ($vec as $term => $freq) {
			$sum += $freq;
		}
		return $sum;
	}



	/**
	 * Use the cosine similarity measure to compare two term vectors.
	 */
	public static function compareTermVectors($vec1, $vec2) {
		$totalScore = 0;
		$sum1 = MetaMan::getFreqSum($vec1);
		$sum2 = MetaMan::getFreqSum($vec2);
		if ($sum1 == 0 or $sum2 == 0) {
			# A page might contain no tokens at all, thus no freq. sum.
			return 0;
		}
	    foreach ($vec2 as $term => $freq) {
	    	if (array_key_exists($term, $vec1)) {
	    		$totalScore += $vec1[$term] * $vec2[$term];
				MetaMan::$debugInfo .= '<span style="background-color:'.
					'rgb(127,255,127)">'.$term.'</span> ';
	    	}
	    }
		MetaMan::$debugInfo .= '<br />';
	    return $totalScore / ($sum1 * $sum2);	
	}



	/**
	 * Get all possible pairings of the wiki's pages. If the comparison of two
	 * pages is not up to date (a page's revision changed), create term vectors
	 * for them and compare them again (or for the first time).
	 */
	public static function iteratePagePairs($pageRevs) {	
		if (MetaMan::getStopwords()) {
			MetaMan::$debugInfo .= '<p>CHECK: Stopword lists read.</p>';
		} else {
			MetaMan::$debugInfo .= '<p class="error">ERROR: There seems to be '.
				'something wrong with the stopword lists.</p>';
		}
	
		MetaMan::$debugInfo .= '<p>';
		for ($i = 0; $i < count($pageRevs); $i += 1) {
			$id1 = $pageRevs[$i][0];
			$rev1 = $pageRevs[$i][1];
			$title1 = $pageRevs[$i][2];
			$textid1 = $pageRevs[$i][3];
		
			for ($k = $i+1; $k <= count($pageRevs)-1; $k += 1) {			
				$id2 = $pageRevs[$k][0];
				$rev2 = $pageRevs[$k][1];
				$title2 = $pageRevs[$k][2];
				$textid2 = $pageRevs[$k][3];
			
				if (!MetaMan::pageRevUpToDate($id1, $rev1) or 
					!MetaMan::pageRevUpToDate($id2, $rev2)) {
					# At least one of two pages is out of date, do comparison
					# again.							
					$vec1 = MetaMan::getTermVector(
						MetaMan::getPageText($textid1));
					$vec2 = MetaMan::getTermVector(
						MetaMan::getPageText($textid2));
					$sim = MetaMan::compareTermVectors($vec1, $vec2);
					MetaMan::$debugInfo .= '<span style="font-weight:bold">'.
						'['.$title1.']:['.$title2.']='.$sim.'</span><br />';
		
					MetaMan::storeSim($id1, $id2, $sim);
				}
			}	
			MetaMan::storePageRev($id1, $rev1);
		}
		MetaMan::$debugInfo .= '</p>';
	}



	/**
	 * Read stopword lists used for filtering out unwanted tokens during term
	 * vectorization.
	 */
	public static function getStopwords() {	
		$paths = array(
			MetaMan::getBaseDir().'stopwords/default.txt',
			MetaMan::getBaseDir().'stopwords/de.txt',
			MetaMan::getBaseDir().'stopwords/de_goetze_geyer.txt', 
			MetaMan::getBaseDir().'stopwords/en.txt',
			MetaMan::getBaseDir().'stopwords/en_brahaj.txt');
	
		MetaMan::$stopwords = array();
		foreach ($paths as $path) {
			$fc = explode("\n", MetaMan::getFileContent($path));
			foreach ($fc as $stopword) {
				MetaMan::$stopwords[$stopword] = 1;
			}		
		}
/* DIESE STOPWORDS IN EIGENE DATEI SCHIEBEN (kommen aus de.txt)
'aber' => 1, 'alle' => 1, 'allen' => 1, 'alles' => 1, 'als' => 1, 'also' => 1, 'an' => 1, 'andere' => 1, 'anderem' => 1, 'anderer' => 1, 'anderes' => 1, 'anders' => 1, 'auch' => 1, 'auf' => 1, 'aus' => 1, 'ausser' => 1, 'ausserdem' => 1, 'bei' => 1, 'beide' => 1, 'beiden' => 1, 'beides' => 1, 'beim' => 1, 'bereits' => 1, 'bestehen' => 1, 'besteht' => 1, 'bevor' => 1, 'bin' => 1, 'bis' => 1, 'bloss' => 1, 'brauchen' => 1, 'braucht' => 1, 'da' => 1, 'dabei' => 1, 'dadurch' => 1, 'dagegen' => 1, 'daher' => 1, 'damit' => 1, 'danach' => 1, 'dann' => 1, 'dar' => 1, 'darf' => 1, 'darueber' => 1, 'darum' => 1, 'darunter' => 1, 'darüber' => 1, 'das' => 1, 'dass' => 1, 'davon' => 1, 'dazu' => 1, 'dem' => 1, 'den' => 1, 'denen' => 1, 'denn' => 1, 'der' => 1, 'deren' => 1, 'des' => 1, 'deshalb' => 1, 'dessen' => 1, 'die' => 1, 'dies' => 1, 'diese' => 1, 'diesem' => 1, 'diesen' => 1, 'dieser' => 1, 'dieses' => 1, 'doch' => 1, 'dort' => 1, 'duerfen' => 1, 'durch' => 1, 'durfte' => 1, 'durften' => 1, 'ebenfalls' => 1, 'ebenso' => 1, 'ein' => 1, 'eine' => 1, 'einem' => 1, 'einen' => 1, 'einer' => 1, 'eines' => 1, 'einige' => 1, 'einiges' => 1, 'einzig' => 1, 'entweder' => 1, 'er' => 1, 'erst' => 1, 'erste' => 1, 'ersten' => 1, 'es' => 1, 'etwa' => 1, 'etwas' => 1, 'falls' => 1, 'fast' => 1, 'ferner' => 1, 'folgender' => 1, 'folglich' => 1, 'für' => 1, 'ganz' => 1, 'geben' => 1, 'gegen' => 1, 'gehabt' => 1, 'gekonnt' => 1, 'gemaess' => 1, 'getan' => 1, 'gewesen' => 1, 'gewollt' => 1, 'geworden' => 1, 'gibt' => 1, 'habe' => 1, 'haben' => 1, 'haette' => 1, 'haetten' => 1, 'hallo' => 1, 'hat' => 1, 'hatte' => 1, 'hatten' => 1, 'heraus' => 1, 'herein' => 1, 'hier' => 1, 'hin' => 1, 'hinein' => 1, 'hinter' => 1, 'ich' => 1, 'ihm' => 1, 'ihn' => 1, 'ihnen' => 1, 'ihr' => 1, 'ihre' => 1, 'ihrem' => 1, 'ihren' => 1, 'ihrer' => 1, 'ihres' => 1, 'im' => 1, 'immer' => 1, 'in' => 1, 'indem' => 1, 'infolge' => 1, 'innen' => 1, 'innerhalb' => 1, 'ins' => 1, 'inzwischen' => 1, 'irgend' => 1, 'irgendwas' => 1, 'irgendwen' => 1, 'irgendwer' => 1, 'irgendwie' => 1, 'irgendwo' => 1, 'ist' => 1, 'jede' => 1, 'jedem' => 1, 'jeden' => 1, 'jeder' => 1, 'jedes' => 1, 'jedoch' => 1, 'jene' => 1, 'jenem' => 1, 'jenen' => 1, 'jener' => 1, 'jenes' => 1, 'kann' => 1, 'kein' => 1, 'keine' => 1, 'keinem' => 1, 'keinen' => 1, 'keiner' => 1, 'keines' => 1, 'koennen' => 1, 'koennte' => 1, 'koennten' => 1, 'konnte' => 1, 'konnten' => 1, 'kuenftig' => 1, 'leer' => 1, 'machen' => 1, 'macht' => 1, 'machte' => 1, 'machten' => 1, 'man' => 1, 'manch' => 1, 'manche' => 1, 'manches' => 1, 'mancher' => 1, 'manchen' => 1, 'mehr' => 1, 'mehrere' => 1, 'mehreren' => 1, 'mein' => 1, 'meine' => 1, 'meinen' => 1, 'meinem' => 1, 'meiner' => 1, 'meist' => 1, 'meiste' => 1, 'meisten' => 1, 'mich' => 1, 'mit' => 1, 'möchte' => 1, 'möchten' => 1, 'möchtest' => 1, 'müssen' => 1, 'müssten' => 1, 'muss' => 1, 'musste' => 1, 'mussten' => 1, 'nach' => 1, 'nachdem' => 1, 'nacher' => 1, 'naemlich' => 1, 'neben' => 1, 'nein' => 1, 'nicht' => 1, 'nichts' => 1, 'noch' => 1, 'nuetzt' => 1, 'nur' => 1, 'nutzt' => 1, 'obgleich' => 1, 'obwohl' => 1, 'oder' => 1, 'ohne' => 1, 'per' => 1, 'pro' => 1, 'rund' => 1, 'schon' => 1, 'sehr' => 1, 'seid' => 1, 'sein' => 1, 'seine' => 1, 'seinem' => 1, 'seinen' => 1, 'seiner' => 1, 'seit' => 1, 'seitdem' => 1, 'seither' => 1, 'selber' => 1, 'sich' => 1, 'sie' => 1, 'siehe' => 1, 'sind' => 1, 'so' => 1, 'sobald' => 1, 'solange' => 1, 'solch' => 1, 'solche' => 1, 'solchem' => 1, 'solchen' => 1, 'solcher' => 1, 'solches' => 1, 'soll' => 1, 'sollen' => 1, 'sollte' => 1, 'sollten' => 1, 'somit' => 1, 'sondern' => 1, 'soweit' => 1, 'sowie' => 1, 'spaeter' => 1, 'stets' => 1, 'such' => 1, 'über' => 1, 'um' => 1, 'ums' => 1, 'und' => 1, 'uns' => 1, 'unser' => 1, 'unsere' => 1, 'unserem' => 1, 'unseren' => 1, 'unter' => 1, 'viel' => 1, 'viele' => 1, 'vollstaendig' => 1, 'vom' => 1, 'von' => 1, 'vor' => 1, 'vorbei' => 1, 'vorher' => 1, 'vorueber' => 1, 'waehrend' => 1, 'waere' => 1, 'waeren' => 1, 'wann' => 1, 'war' => 1, 'waren' => 1, 'warum' => 1, 'was' => 1, 'wegen' => 1, 'weil' => 1, 'weiter' => 1, 'weitere' => 1, 'weiterem' => 1, 'weiteren' => 1, 'weiterer' => 1, 'weiteres' => 1, 'weiterhin' => 1, 'welche' => 1, 'welchem' => 1, 'welchen' => 1, 'welcher' => 1, 'welches' => 1, 'wem' => 1, 'wen' => 1, 'wenigstens' => 1, 'wenn' => 1, 'wenngleich' => 1, 'wer' => 1, 'werde' => 1, 'werden' => 1, 'weshalb' => 1, 'wessen' => 1, 'wie' => 1, 'wieder' => 1, 'will' => 1, 'wir' => 1, 'wird' => 1, 'wodurch' => 1, 'wohin' => 1, 'wollen' => 1, 'wollte' => 1, 'wollten' => 1, 'womit' => 1, 'worin' => 1, 'wovon' => 1, 'wuerde' => 1, 'wuerden' => 1, 'wurde' => 1, 'wurden' => 1, 'zu' => 1, 'zudem' => 1, 'zufolge' => 1, 'zum' => 1, 'zusammen' => 1, 'zur' => 1, 'zwar' => 1, 'zwischen' => 1,		
PYTHON CODE:
------------------------------------------------------------------------------------------------
p = '/Applications/XAMPP/xamppfiles/htdocs/mediawiki-1.15.0/extensions/metaman/stopwords/de.txt'
f=open(p, encoding='utf-8')
s=f.read()
l = s.split()
for w in l:
	print("'"+w+"' => 1, ", end='')		
------------------------------------------------------------------------------------------------
*/
		return true;
	}



	/**
	 * Main function for the retrieval of category and property
	 * suggestions. Takes care of the involved DB tables and returns the
	 * suggestions to the Javascript function that called this function.
	 */
	public static function getSuggestions($id) {
		global $wgLanguageCode;
		MetaMan::$debugInfo = '<p>$wgLanguageCode='.$wgLanguageCode.'</p>';
		
		if (MetaMan::setupDatabase()) {
			MetaMan::$debugInfo .= '<p>CHECK: Database seems alright.</p>';
		} else {
			MetaMan::$debugInfo .= '<p class="error">ERROR: There seems to be '.
				'something wrong with the database.</p>';
		}
	
		# Get textIDs for the latest revisions of all pages		
		$pageRevs = MetaMan::getLatestPageRevisions();

		# Create similarity matrix
		MetaMan::$debugInfo .= '<p>Comparing pages</p>';
		MetaMan::iteratePagePairs($pageRevs);

		# Retrieving suggestions
		MetaMan::$debugInfo .= 
			'<p>Retrieving suggestions from similar pages</p>';
	
		$categories = array();
		$properties = array();
		$currTitle = MetaMan::getPageTitle($id);
		foreach (MetaMan::getSimPages($id, MetaMan::$simPagesThreshold) as $simpage) {
			$partnerID = $simpage[0];
			$sim = $simpage[1];
			$text = MetaMan::getWikiText($partnerID);
			$title = MetaMan::getPageTitle($partnerID);
			MetaMan::$debugInfo .= '['.$currTitle.']:['.$title.']='.$sim.
				'<br />';
			$categories[] = array($sim, MetaMan::getCategories($text));
			$properties[] = array($sim, MetaMan::getProperties($text));
		}
	
		return json_encode(array(
			'categorySuggestions' =>
			 	MetaMan::getCategorySuggestions($categories),
			'propertySuggestions' =>
			 	MetaMan::getPropertySuggestions($properties),
			'debugInfo' => MetaMan::getDebugInfo()));
	}



	/** 
	 * Compose the MetaMan menu and insert it into the EditPage.
	 */
	public static function composeMenu($editpage) {	
		global $wgUseAjax;
		
		# Load internationalized messages
		wfLoadExtensionMessages('MetaMan');
			
		$id = $editpage->getArticle()->getID(); # ID of this page		
	
		# Load CSS, Javascript and HTML into edit page
		$css = '<style type="text/css">'.
			MetaMan::getFileContent(MetaMan::getBaseDir().'metaman.css').
			'</style>';
			
		$js = '<script language="javascript" type="text/javascript">'.
			MetaMan::getFileContent(MetaMan::getBaseDir().'json2.js').
			sprintf(
				MetaMan::getFileContent(MetaMan::getBaseDir().'metaman.js'),
				wfMsg('menu_emptylist'), wfMsg('menu_sugg_cats'),
				wfMsg('menu_sugg_props'), 'Category',
				MetaMan::getCategoryNsTransl(), $id).
			'</script>';
			
		$html = sprintf(
			MetaMan::getFileContent(MetaMan::getBaseDir().'metaman.html'),
			wfMsg('menu_title'), wfMsg('menu_usage'), wfMsg('menu_categories'),
			wfMsg('menu_properties'), wfMsg('menu_sugg_cats'),
			wfMsg('menu_loading'), wfMsg('menu_sugg_props'),
			wfMsg('menu_loading')); 
		
	
		# Check if MediaWiki's AJAX interface is enabled
		if (!$wgUseAjax) {
			echo 'Sorry, the MetaMan extension requires $wgUseAjax set to'.
			 	' "true".<br />You might want to add the folling line to your'.
				'LocalSettings.php: <br />$wgUseAjax = true;';
			exit(1);
		}
	
		$editpage->editFormTextAfterWarn .= $css . $js . $html;
		return true;
	}
}