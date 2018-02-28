<?PHP

/*
error_reporting(E_ERROR|E_CORE_ERROR|E_ALL|E_COMPILE_ERROR);
ini_set('display_errors', 'On');

ini_set('memory_limit','500M');
set_time_limit ( 60 * 10 ) ; // Seconds
*/

define('CLI', PHP_SAPI === 'cli');
ini_set('user_agent','Magnus labs tools'); # Fake user agent
if ( !isset($noheaderwhatsoever) ) header("Connection: close");
#$index_file = '/data/project/magnustools/public_html/resources/html/dummy_header_bs4.html' ;
#$index_file = '/data/project/magnustools/public_html/resources/html/dummy_header.html' ;
$tools_webproxy = 'tools-webproxy' ;
$tusc_url = "http://$tools_webproxy/tusc/tusc.php" ; // http://tools-webserver-01/ // tools.wmflabs.org
$use_db_cache = false ;
$common_db_cache = array() ;
$wdq_internal_url = 'http://wdq.wmflabs.org/api' ; // 'http://wikidata-wdq-mm.eqiad.wmflabs/api'
$pagepile_enabeled = true ; //isset($_REQUEST['pagepile_enabeled']) ;
$maxlag = 5 ; // https://www.mediawiki.org/wiki/Manual:Maxlag_parameter

$petscan_note = "<div style='margin:3px;padding:3px;text-align:center;background-color:#d9edf7;'>Please try <a href='https://petscan.wmflabs.org/'>PetScan</a>, the designated successor to this tool!</div>" ;
$petscan_note2 = "<div style='margin:3px;padding:3px;text-align:center;background-color:#FF4848;font-size:12pt;'><b>This tool will be replaced with <a href='https://petscan.wmflabs.org/'>PetScan</a> in the next few days!</b><br/>" ;
$petscan_note2 .= "All links to this tool with URL parameters will be forwarded to PetScan, and should remain functional.<br/>" ;
$petscan_note2 .= "Please add any blocking issues to the <a href='https://bitbucket.org/magnusmanske/petscan/issues?status=new&status=open'>PetScan bug tracker</a>.</div>" ;

function myurlencode ( $t ) {
	$t = str_replace ( " " , "_" , $t ) ;
	$t = urlencode ( $t ) ;
	return $t ;
}

function getWebserverForWiki ( $wiki ) {
	$wiki = preg_replace ( '/_p$/' , '' , $wiki ) ; // Paranoia
	if ( $wiki == 'commonswiki' ) return "commons.wikimedia.org" ;
	if ( $wiki == 'wikidatawiki' ) return "www.wikidata.org" ;
	if ( $wiki == 'specieswiki' ) return "species.wikimedia.org" ;
	if ( $wiki == '172.20.48.41' ) return "172.20.48.41" ;
	$wiki = preg_replace ( '/_/' , '-' , $wiki ) ;
	if ( preg_match ( '/^(.+)wiki$/' , $wiki , $m ) ) return $m[1].".wikipedia.org" ;
	if ( preg_match ( '/^(.+)(wik.+)$/' , $wiki , $m ) ) return $m[1].".".$m[2].".org" ;
	return '' ;
}

function escape_attribute ( $s ) {
	$ret = preg_replace ( "/\"/" , '&quot;' , $s ) ;
	$ret = preg_replace ( "/'/" , '&apos;' , $ret ) ;
	return $ret ;
}

function getDBpassword () {
	global $mysql_user , $mysql_password , $tool_user_name ;
	if ( isset ( $tool_user_name ) and $tool_user_name != '' ) $user = $tool_user_name ;
	else $user = str_replace ( 'tools.' , '' , get_current_user() ) ;
	$passwordfile = '/data/project/' . $user . '/replica.my.cnf' ;
	if ( $user == 'magnus' ) $passwordfile = '/home/' . $user . '/replica.my.cnf' ; // Command-line usage
	$config = parse_ini_file( $passwordfile );
	if ( isset( $config['user'] ) ) {
		$mysql_user = $config['user'];
	}
	if ( isset( $config['password'] ) ) {
		$mysql_password = $config['password'];
	}
}

function getDBname ( $language , $project ) {
	$ret = $language ;
	if ( $language == 'commons' ) $ret = 'commonswiki_p' ;
	elseif ( $language == 'wikidata' || $project == 'wikidata' ) $ret = 'wikidatawiki_p' ;
	elseif ( $language == 'mediawiki' || $project == 'mediawiki' ) $ret = 'mediawikiwiki_p' ;
	elseif ( $language == 'species' || $project == 'wikimedia' ) $ret = 'specieswiki_p' ;
	elseif ( $language == 'meta' && $project == 'wikimedia' ) $ret = 'metawiki_p' ;
	elseif ( $project == 'wikipedia' ) $ret .= 'wiki_p' ;
	elseif ( $project == 'wikisource' ) $ret .= 'wikisource_p' ;
	elseif ( $project == 'wiktionary' ) $ret .= 'wiktionary_p' ;
	elseif ( $project == 'wikibooks' ) $ret .= 'wikibooks_p' ;
	elseif ( $project == 'wikinews' ) $ret .= 'wikinews_p' ;
	elseif ( $project == 'wikiversity' ) $ret .= 'wikiversity_p' ;
	elseif ( $project == 'wikivoyage' ) $ret .= 'wikivoyage_p' ;
	elseif ( $project == 'wikiquote' ) $ret .= 'wikiquote_p' ;
	elseif ( $project == 'wikispecies' ) $ret = 'specieswiki_p' ;
	elseif ( $language == 'meta' ) $ret .= 'metawiki_p' ;
	else if ( $project == 'wikimedia' ) $ret .= $language.$project."_p" ;
	else die ( "Cannot construct database name for $language.$project - aborting." ) ;
	return $ret ;
}

function openToolDB ( $dbname = '' , $server = '' , $force_user = '' ) {
	global $o , $mysql_user , $mysql_password ;
	getDBpassword() ;
	if ( $dbname == '' ) $dbname = '_main' ;
	else $dbname = "__$dbname" ;
	if ( $force_user == '' ) $dbname = $mysql_user.$dbname;
	else $dbname = $force_user.$dbname;
	if ( $server == '' ) $server = "tools.labsdb" ; //"tools-db" ;
	$db = new mysqli($server, $mysql_user, $mysql_password, $dbname);
	if($db->connect_errno > 0) {
#print "<pre>SERVER:$server\nSCHEMA:$dbname\nERROR:{$db->connect_error} [{$db->connect_errno}]</pre>" ;
		$o['msg'] = 'Unable to connect to database [' . $db->connect_error . ']';
		$o['status'] = 'ERROR' ;
		return false ;
	}
	return $db ;
}

function openDBwiki ( $wiki , $slow_queries = false ) {
	preg_match ( '/^(.+)(wik.+)$/' , $wiki , $m ) ;
	if ( $m == null ) {
		print "Cannot parse $wiki\n" ;
		return ;
	}
	if ( $m[2] == 'wiki' ) $m[2] = 'wikipedia' ;
	return openDB ( $m[1] , $m[2] , $slow_queries ) ;
}

function openDB ( $language , $project , $slow_queries = false ) {
	global $mysql_user , $mysql_password , $o , $common_db_cache , $use_db_cache ;

	$db_key = "$language.$project" ;
	if ( isset ( $common_db_cache[$db_key] ) ) return $common_db_cache[$db_key] ;
	
	getDBpassword() ;
	$dbname = getDBname ( $language , $project ) ;
	
	$servers = array (
		'.web.db.svc.eqiad.wmflabs' ,
		'.analytics.db.svc.eqiad.wmflabs' ,
		'.labsdb'
	) ;

	# Try optimal server
	$server = substr( $dbname, 0, -2 ) . ( $slow_queries ? $servers[1] : $servers[0] ) ;
	$db = new mysqli($server, $mysql_user, $mysql_password, $dbname);

	if ( $db->connect_errno > 0 and preg_match ( '/max_user_connections/' , $db->connect_error ) ) {
		$seconds = rand ( 10 , 60*10 ) ; // Random delay
		sleep ( $seconds ) ;
		return openDB ( $language , $project , $slow_queries ) ;
	}
	
	# Try the other server
	if($db->connect_errno > 0 ) {
		$server = substr( $dbname, 0, -2 ) . ( $slow_queries ? $servers[0] : $servers[1] ) ;
		$db = new mysqli($server, $mysql_user, $mysql_password, $dbname);
	}

	# Try the old server as fallback
	if($db->connect_errno > 0) {
		$server = substr( $dbname, 0, -2 ) . $servers[2];
		$db = new mysqli($server, $mysql_user, $mysql_password, $dbname);
	}
	
	if($db->connect_errno > 0) {
		$o['msg'] = 'Unable to connect to database [' . $db->connect_error . ']';
		$o['status'] = 'ERROR' ;
		return false ;
	}
	if ( $use_db_cache ) $common_db_cache[$db_key] = $db ;
	return $db ;
}

function get_db_safe ( $s , $fixup = false ) {
	global $db ;
	if ( $fixup ) $s = str_replace ( ' ' , '_' , trim ( ucfirst ( $s ) ) ) ;
	if ( !isset($db) ) return addslashes ( str_replace ( ' ' , '_' , $s ) ) ;
	return $db->real_escape_string ( str_replace ( ' ' , '_' , $s ) ) ;
}

function make_db_safe ( &$s , $fixup = false ) {
	$s = get_db_safe ( $s , $fixup ) ;
}

function getSQL ( &$db , &$sql , $max_tries = 2 , $message = '' ) {
	while ( $max_tries > 0 ) {
		while ( !@$db->ping() ) {
//			print "RECONNECTING..." ;
			sleep ( 1 ) ;
			@$db->connect() ;
		}
		if($ret = @$db->query($sql)) return $ret ;
		$max_tries-- ;
	}
	//debug_print_backtrace() ;
	die('There was an error running the query [' . $db->error . ']'."\n$sql\n$message\n");
}


function findSubcats ( $db , $root , &$subcats , $depth = -1 ) {
	global $testing ;
	$check = array() ;
	$c = array() ;
	foreach ( $root AS $r ) {
		if ( isset ( $subcats[$r] ) ) continue ;
		$subcats[$r] = $db->real_escape_string ( $r ) ;
		$c[] = $db->real_escape_string($r); //str_replace ( ' ' , '_' , $db->escape_string ( $r ) ) ;
	}
	if ( count ( $c ) == 0 ) return ;
	if ( $depth == 0 ) return ;
	$sql = "SELECT DISTINCT page_title FROM page,categorylinks WHERE page_id=cl_from AND cl_to IN ('" . implode ( "','" , $c ) . "') AND cl_type='subcat'" ;
	$result = getSQL ( $db , $sql , 2 ) ;
	while($row = $result->fetch_assoc()){
		if ( isset ( $subcats[$row['page_title']] ) ) continue ;
		$check[] = $row['page_title'] ;
	}
	if ( count ( $check ) == 0 ) return ;
	findSubcats ( $db , $check , $subcats , $depth - 1 ) ;
}

function getPagesInCategory ( $db , $category , $depth = 0 , $namespace = 0 , $no_redirects = false ) {
	global $testing ;
	$ret = array() ;
	$cats = array() ;
	findSubcats ( $db , array($category) , $cats , $depth ) ;
	if ( $namespace == 14 ) return $cats ; // Faster, and includes root category

	$namespace *= 1 ;
	$sql = "SELECT DISTINCT page_title FROM page,categorylinks WHERE cl_from=page_id AND page_namespace=$namespace AND cl_to IN ('" . implode("','",$cats) . "')" ;
	if ( $no_redirects ) $sql .= " AND page_is_redirect=0" ;

	$result = getSQL ( $db , $sql , 2 ) ;
	while($o = $result->fetch_object()){
		$ret[$o->page_title] = $o->page_title ;
	}
	return $ret ;
}


//________________________________________________________________________________________
// Misc


function load_common_header () {
	$dir = '/data/project/magnustools/public_html/resources/html' ;
	$f1 = file_get_contents ( "$dir/index_bs4.html" ) ;
	$f2 = file_get_contents ( "$dir/menubar_bs4.html" ) ;

	$f1 = preg_replace ( '/<body>.*/ms' , "<body>\n" , $f1 ) ;
	$f1 = preg_replace ( '/<script src=".\/main.js"><\/script>\s*/' , '' , $f1 ) ;
	$f3 = '<div id="main_content" class="container"><div class="row"><div class="col-sm-12" style="margin-bottom:20px;margin-top:10px;">' ;

	return "$f1$f2$f3\n" ;
}

function get_common_header ( $script , $title , $p = array() ) {
	global $index_file ;
	if ( !headers_sent() ) {
		header('Content-type: text/html; charset=UTF-8'); // UTF8 test
		header("Cache-Control: no-cache, must-revalidate");
	}
//	$s = file_get_contents ( $index_file ) ;
	$s = load_common_header() ;
	if ( isset ( $p['style'] ) ) $s = str_replace ( '</style>' , $p['style'].'</style>' , $s ) ;
	if ( isset ( $p['script'] ) ) $s = str_replace ( '</script>' , $p['script'].'</script>' , $s ) ;
	if ( isset ( $p['title'] ) ) $s = str_replace ( '</head>' , "<title>{$p['title']}</title></head>" , $s ) ;
	
	$misc = '' ;
	if ( isset ( $p['link'] ) ) $misc .= $p['link'] ;
	$s = str_replace ( '<!--header_misc-->' , $misc , $s ) ;
	
	$s = str_replace ( '$$TITLE$$' , $title , $s ) ;
	return $s ;
}

function get_common_footer() {
	return "</div></div></body></html>" ;
}


function get_request ( $key , $default = "" ) {
	global $prefilled_requests ;
	if ( isset ( $prefilled_requests[$key] ) ) return $prefilled_requests[$key] ;
	if ( isset ( $_REQUEST[$key] ) ) return str_replace ( "\'" , "'" , $_REQUEST[$key] ) ;
	return $default ;
}


function do_post_request($url, $data, $optional_headers = null)
{
 $params = array('http' => array(
			  'method' => 'POST',
			  'content' => http_build_query ( $data ) 
		   ));
 if ($optional_headers !== null) {
	$params['http']['header'] = $optional_headers;
 }
 $ctx = stream_context_create($params);
 $fp = @fopen($url, 'rb', false, $ctx);
 if (!$fp) {
	throw new Exception("Problem with $url"); // , $php_errormsg
 }
 $response = @stream_get_contents($fp);
 if ($response === false) {
	throw new Exception("Problem reading data from $url, $php_errormsg");
 }
 return $response;
}


function verify_tusc () {
return false ; // TUSC deactivated
	global $tusc_user , $tusc_password , $tusc_url ;
	if ( $tusc_user == '' ) return false ;
	if ( $tusc_password == '' ) return false ;
	$ret = '' ;
	
	$ret = do_post_request ( $tusc_url , 
		array (
			'check' => '1' ,
			'botmode' => '1' ,
			'user' => $tusc_user ,
			'language' => 'commons' ,
			'project' => 'wikimedia' ,
			'password' => $tusc_password ) ) ;

	if ( strpos ( $ret , '1' ) !== false ) return true ;
	return false ;
}

function verify_tusc_detail () {
return false ; // TUSC deactivated
	global $tusc_user , $tusc_password , $tusc_url ;
	if ( $tusc_user == '' ) return false ;
	if ( $tusc_password == '' ) return false ;

	$ret = do_post_request ( $tusc_url ,
 		 array (
				'action' => 'check_password' ,
				'user' => $tusc_user ,
				'language' => 'commons' ,
				'project' => 'wikimedia' ,
				'password' => $tusc_password ) ) ;

	$ret = json_decode ( $ret ) ;
	return $ret ;
}

function myflush () {
#	for ($i = 0; $i < ob_get_level(); $i++) { ob_end_flush(); }
#	ob_implicit_flush(1);
#while ( @ob_end_flush() );
	@ob_flush();
     flush();
}

function pre ( $d ) {
	print "<pre>" ;
	print_r ( $d ) ;
	print "</pre>" ;
}

function pluralPl( $count, $forms ) {
	if ( !count($forms) ) { return ''; }
#	$forms = $this->preConvertPlural( $forms, 3 );
	$count = abs( $count );
	if ( $count == 1 )
		return $forms[0];     // singular
	switch ( $count % 10 ) {
		case 2:
		case 3:
		case 4:
			if ( $count / 10 % 10 != 1 )
				return $forms[1]; // plural
		default:
			return $forms[2];   // plural genitive
	}
}

function bytes ( $nr ) {
	global $language ;
	if ( $language == 'pl' ) {
		$f = array ( 'bajt','bajty','bajtów' ) ;
		return "$nr " . pluralPl ( $nr , $f ) ;
	} else {
		return "$nr bytes" ;
	}
}



//________________________________________________________________________________________
// LEGACY STUFF (should be replaced)


/**
 * Returns the raw text of a wikipedia page, trimmed and with html comments removed
 * Returns empty string if something went wrong
 */
function get_wikipedia_article ( $lang , $title , $allow_redirect = true , $project = "wikipedia" , $remove_comments = true) {
	global $is_on_toolserver ;
	if ( $is_on_toolserver ) {
		$u = myurlencode ( $title ) ;
		$url = "http://tools.wikimedia.de/~daniel/WikiSense/WikiProxy.php?wiki={$lang}.{$project}.org&title={$u}&rev=0&go=Fetch" ;
#		$text = get_article_from_database ( $lang , $title ) ;
	} else {
		$url = get_wikipedia_url ( $lang , $title , "raw" , $project ) ;
	}
	$max_attempts = 2 ;
	$cnt = 0 ;
	do {
		$text = @file_get_contents ( $url ) ;
		$cnt++ ;
		if ( $cnt > 1 ) $url = get_wikipedia_url ( $lang , $title , "raw" , $project ) ; # On toolserver, try alternate URL
	} while ( $text === false && $cnt < $max_attempts ) ;
	if ( $text === false ) {
		# Wikipedia did not return anything
		$text = "" ;
	}
	if ( substr ( $text , 0 , 10 ) == '<!DOCTYPE ' ) {
		# Wikipedia did not return raw text
		$text = "" ;
	}

	if ( $remove_comments ) $text = trim ( strip_html_comments ( $text ) ) ;
	else $text = trim ( $text ) ;
	
	# REDIRECT?
	if ( $allow_redirect && strtoupper ( substr ( $text , 0 , 9 ) ) == "#REDIRECT" ) {
		$text = substr ( $text , 9 ) ;
		$text = explode ( "\n" , $text , 2 ) ;
		$text = array_shift ( $text ) ;
		$text = str_replace ( "[[" , "" , $text ) ;
		$text = str_replace ( "]]" , "" , $text ) ;
		$text = ucfirst ( trim ( $text ) ) ;
#		print "Redirected to {$text}<br/>" ;
		return get_wikipedia_article ( $lang , $text , false ) ;
	}
	return $text ;
}

/**
 * Returns the URL for a language/title combination
 * May be called with additional parameter $action
 */
function get_wikipedia_url ( $lang , $title , $action = "" , $project = "wikipedia" ) {
	$lang = trim ( strtolower ( $lang ) ) ;
	$url = "http://" ;
	if ( $lang != 'xxx' ) $url .= "{$lang}." ;
	if ( $lang == "commons" ) $url .= "wikimedia" ;
	else $url .= $project ;
	$url .= ".org/w/index.php?" ;
	if ( $action != "" ) $url .= "action={$action}&" ;
	$url .= "title=" . myurlencode ( $title ) ;
	return $url ;
}

function strip_html_comments ( &$text ) {
	return preg_replace( '?<!--.*-->?msU', '', $text);
}

function get_image_url ( $lang , $image , $project = "wikipedia" ) {
	if ( $lang == 'commons' ) $project = 'wikimedia' ;
#	$image = ucfirst ( str_replace ( " " , "_" , $image ) ) ;
	$url = "//{$lang}.{$project}.org/wiki/Special:Redirect/file/" . myurlencode ( $image );
	return $url ;
}

function get_thumbnail_url ( $lang , $image , $width , $project = "wikipedia" ) {
	if ( $lang == 'commons' ) $project = 'wikimedia' ;
	$url = "//{$lang}.{$project}.org/wiki/Special:Redirect/file/" . myurlencode ( $image ) . "?width=". $width;
	return $url ;
}

function get_edit_timestamp ( $lang , $project , $title ) {
	$t = "http://{$lang}.{$project}.org/w/index.php?title=Special:Export/" . myurlencode ( $title ) ;
	$t = @file_get_contents ( $t ) ;
#	$desc = $t ;
	$t = explode ( '<timestamp>' , $t , 2 ) ;
	$t = explode ( '</timestamp>' , array_pop ( $t ) , 2 ) ;
	$t = array_shift ( $t ) ;
	$t = str_replace ( '-' , '' , $t ) ;
	$t = str_replace ( ':' , '' , $t ) ;
	$t = str_replace ( 'T' , '' , $t ) ;
	$t = str_replace ( 'Z' , '' , $t ) ;
	return $t ;
}

function cGetEditButton ( $text , $title , $lang , $project , $summary , $button_label , $new_window = true , $add = false , $diff = false , $minor = false , $section = -1 , $blank_init = false ) {
	global $toynote ;
	if ( !isset ( $toynote ) ) $toynote = '' ;
	
	$t = get_edit_timestamp ( $lang , $project , $title ) ;
	if ( $blank_init ) $t = '' ;
	$timestamp = $t ;
	
	$text = str_replace ( "'" , htmlentities ( "'" , ENT_QUOTES ) , $text ) ;
	$summary = str_replace ( "'" , htmlentities ( "'" , ENT_QUOTES ) , $summary.$toynote ) ;

	$url = "//{$lang}.{$project}.org/w/index.php?title=" . myurlencode ( $title ) . '&action=edit' ;
	if ( $add ) $url .= '&section=new' ;
	elseif ( $section >= 0 ) $url .= "&section=$section" ;
	$ncb = "<form id='upload' method=post enctype='multipart/form-data'" ;
	if ( $new_window ) $ncb .= " target='_blank'" ;
	$ncb .= " action='{$url}' style='display:inline'>" ;
	$ncb .= "<input type='hidden' name='wpTextbox1' value='{$text}'/>" ;
	$ncb .= "<input type='hidden' name='wpSummary' value='{$summary}'/>" ;
	if ( $diff ) $ncb .= "<input type='hidden' name='wpDiff' value='wpDiff' />" ;
	else $ncb .= "<input type='hidden' name='wpPreview' value='wpPreview' />" ;
	
	$starttime = date ( "YmdHis" , time() + (12 * 60 * 60) ) ;
	$ncb .= "<input type='hidden' value='{$starttime}' name='wpStarttime' />" ;
	$ncb .= "<input type='hidden' value='{$t}' name='wpEdittime' />" ;

  if ( $minor ) $ncb .= "<input type='hidden' value='1' name='wpMinoredit' />" ;
	if ( $diff ) $ncb .= "<input type='submit' name='wpDiff' value='$button_label'/>" ;
	else $ncb .= "<input class='btn btn-primary' type='submit' name='wpPreview' value='$button_label'/>" ;
	$ncb .= "</form>" ;
	return $ncb ;
}

function db_get_user_images ( $username , $db ) {
	$username = $db->real_escape_string ( $username ) ;
	$username = str_replace ( '_' , ' ' , $username ) ;

	$ret = array () ;
	$sql = "SELECT  * FROM image WHERE img_user_text=\"{$username}\"" ;
	$result = getSQL ( $db , $sql ) ;
	while($o = $result->fetch_object()){
		$ret[$o->img_name] = $o ;
	}
	return $ret ;
}

function get_initial_paragraph ( &$text , $language = '' ) {
	global $image_aliases ;
	$t = explode ( "\n" , $text ) ;
	while ( count ( $t ) > 0 ) {
		$s = trim ( array_shift ( $t ) ) ;
		if ( $s == "" ) continue ;
		if ( substr ( $s , 0 , 2 ) == '{{' ) { # Template
			if ( substr ( $s , -2 , 2 ) == '}}' ) continue ; # One-line template
			while ( count ( $t ) > 0 && substr ( $s , -2 , 2 ) != '}}' ) {
				$s = trim ( array_shift ( $t ) ) ;
			}
			continue ;
		}
		if ( substr ( $s , 0 , 2 ) == '--' ) continue ; # <hr>
		if ( substr ( $s , 0 , 1 ) == ':' ) continue ; # Remark
		if ( substr ( $s , 0 , 1 ) == '*' ) continue ; # List
		if ( substr ( $s , 0 , 1 ) == '#' ) continue ; # List
		if ( substr ( $s , 0 , 1 ) == '=' ) continue ; # Heading
		if ( substr ( $s , 0 , 1 ) == '<' ) continue ; # HTML
		if ( substr ( $s , 0 , 1 ) == '!' ) continue ; # Table fragment
		if ( substr ( $s , 0 , 1 ) == '|' ) continue ; # Table fragment
		if ( substr ( $s , 0 , 2 ) == '|-' ) continue ; # Table fragment
		if ( substr ( $s , 0 , 2 ) == '|}' ) continue ; # Table fragment
		if ( substr ( $s , 0 , 2 ) == '{|' ) { # Table
			while ( count ( $t ) > 0 && substr ( $s , 0 , 2 ) != '|}' ) {
				$s = trim ( array_shift ( $t ) ) ;
			}
			continue ;
		}

		if ( substr ( $s , 0 , 2 ) == '}}' ) continue ; # Template end
		
		$sl = strtolower ( $s ) ;
		
		# Check for images
		foreach ( $image_aliases AS $ia )
			{
			if ( false === strpos ( $sl , '[['.$ia ) ) continue ; # Image
			$sl = '' ;
			break ;
			}
		if ( $sl == '' ) continue ;
		
		if ( false !== strpos ( $sl , "|thumb|" ) ) continue ; # Image
		if ( false !== strpos ( $sl , "|frame|" ) ) continue ; # Image
		if ( false !== strpos ( $sl , "|right|" ) ) continue ; # Image
		if ( false !== strpos ( $sl , "|miniatur|" ) ) continue ; # Image
		if ( false !== strpos ( $sl , "|hochkant=" ) ) continue ; # Image

		if ( $language == 'eo' AND false !== strpos ( $sl , ">>" ) ) continue ; # Esperanto navigation line
		
		# Seems to be a real paragraph
		break ;
	}
	if ( count ( $t ) == 0 ) return "" ;
	return $s ;
}

function fix_language_code ( $s ) {
	return strtolower ( preg_replace ( '/[^a-z-]/' , '' , $s ) ) ;
}

function check_project_name ( $s ) {
	return strtolower ( preg_replace ( '/[^a-z]/' , '' , $s ) ) ;
}

// UPLOAD FILE VIA API

$cookiejar = '' ;
$file_upload_api_result = array() ;

function do_post_request_curl ( $url , $params , $format = 'php' ) {
	global $cookiejar ;
	$params['format'] = $format ;
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_COOKIEJAR, $cookiejar);
	curl_setopt($ch, CURLOPT_COOKIEFILE, $cookiejar);
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
	$output = curl_exec($ch);
	$info = curl_getinfo($ch);
	curl_close($ch);
	if ( $format != 'php' ) return $output ;
	return unserialize ( $output ) ;
}

function uploadFileViaAPI ( $username , $userpass , $local_file , $new_file_name , $desc , $comment , $testing ) {
	global $cookiejar , $file_upload_api_result ;
	$cookiejar = tempnam("/tmp", "magnus_upload_cookiejar");
	$api = 'http://commons.wikimedia.org/w/api.php' ;
	
	$r1 = do_post_request_curl ( $api , array ( 'action' => 'login' , 'lgname' => $username , 'lgpassword' => $userpass ) ) ;
	$r2 = do_post_request_curl ( $api , array ( 'action' => 'login' , 'lgname' => $username , 'lgpassword' => $userpass , 'lgtoken' => $r1['login']['token'] ) ) ;
	$r3 = do_post_request_curl ( $api , array ( 'action' => 'tokens' , 'type' => 'edit' ) ) ;
	$token = $r3['tokens']['edittoken'] ;
	$file_upload_api_result = do_post_request_curl ( $api , array (
		'action' => 'upload' ,
		'filename' => $new_file_name ,
		'comment' => $comment ,
		'text' => $desc ,
		'token' => $token ,
		'file' => '@' . $local_file
	) ) ;
	if ( isset($testing) AND $testing ) {
		print "<pre>" ; print_r ( $r1 ) ; print "</pre>" ;
		print "<pre>" ; print_r ( $r2 ) ; print "</pre>" ;
		print "<pre>" ; print_r ( $r3 ) ; print "</pre>" ;
		print "<pre>" ; print_r ( $file_upload_api_result ) ; print "</pre>" ;
	}
	unlink ( $cookiejar ) ;
	if ( $file_upload_api_result['upload']['result'] == 'Warning' ) return false ;
	return true ; // TODO 
}


function strtolower_utf8($string){
  $convert_to = array(
    "a", "b", "c", "d", "e", "f", "g", "h", "i", "j", "k", "l", "m", "n", "o", "p", "q", "r", "s", "t", "u",
    "v", "w", "x", "y", "z", "à", "á", "â", "ã", "ä", "å", "æ", "ç", "è", "é", "ê", "ë", "ì", "í", "î", "ï",
    "ð", "ñ", "ò", "ó", "ô", "õ", "ö", "ø", "ù", "ú", "û", "ü", "ý", "а", "б", "в", "г", "д", "е", "ё", "ж",
    "з", "и", "й", "к", "л", "м", "н", "о", "п", "р", "с", "т", "у", "ф", "х", "ц", "ч", "ш", "щ", "ъ", "ы",
    "ь", "э", "ю", "я"
  );
  $convert_from = array(
    "A", "B", "C", "D", "E", "F", "G", "H", "I", "J", "K", "L", "M", "N", "O", "P", "Q", "R", "S", "T", "U",
    "V", "W", "X", "Y", "Z", "À", "Á", "Â", "Ã", "Ä", "Å", "Æ", "Ç", "È", "É", "Ê", "Ë", "Ì", "Í", "Î", "Ï",
    "Ð", "Ñ", "Ò", "Ó", "Ô", "Õ", "Ö", "Ø", "Ù", "Ú", "Û", "Ü", "Ý", "А", "Б", "В", "Г", "Д", "Е", "Ё", "Ж",
    "З", "И", "Й", "К", "Л", "М", "Н", "О", "П", "Р", "С", "Т", "У", "Ф", "Х", "Ц", "Ч", "Ш", "Щ", "Ъ", "Ъ",
    "Ь", "Э", "Ю", "Я"
  );

  return str_replace($convert_from, $convert_to, $string);
} 

function getMultipleURLsInParallel ( $urls , $batch_size = 50 ) {
	$ret = array() ;
	
	$batches = array( array() ) ;
	foreach ( $urls AS $k => $v ) {
		if ( count($batches[count($batches)-1]) >= $batch_size ) $batches[] = array() ;
		$batches[count($batches)-1][$k] = $v ;
	}
	
	foreach ( $batches AS $batch_urls ) {
	
		$mh = curl_multi_init();
		curl_multi_setopt  ( $mh , CURLMOPT_PIPELINING , 1 ) ;
	//	curl_multi_setopt  ( $mh , CURLMOPT_MAX_TOTAL_CONNECTIONS , 5 ) ;
		$ch = array() ;
		foreach ( $batch_urls AS $key => $value ) {
			$ch[$key] = curl_init($value);
	//		curl_setopt($ch[$key], CURLOPT_NOBODY, true);
	//		curl_setopt($ch[$key], CURLOPT_HEADER, true);
			curl_setopt($ch[$key], CURLOPT_USERAGENT, "Mozilla/5.0 (Macintosh; Intel Mac OS X 10.10; rv:57.0) Gecko/20100101 Firefox/57.0");
			curl_setopt($ch[$key], CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch[$key], CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch[$key], CURLOPT_SSL_VERIFYHOST, false);
			curl_multi_add_handle($mh,$ch[$key]);
		}
	
		do {
			curl_multi_exec($mh, $running);
			curl_multi_select($mh);
		} while ($running > 0);
	
		foreach(array_keys($ch) as $key){
			$ret[$key] = curl_multi_getcontent($ch[$key]) ;
			curl_multi_remove_handle($mh, $ch[$key]);
		}
	
		curl_multi_close($mh);
	}
	
	return $ret ;
}



function getSPARQLprefixes () {
	$sparql = '' ;

	$toolname = basename($_SERVER["SCRIPT_FILENAME"], '.php') ;
	if ( $toolname == '' or $toolname == 'index' or preg_match ( '/^api/' , $toolname ) ) {
		$toolname = preg_replace ( '/^.+\.org\//' , '' , $_SERVER["SCRIPT_FILENAME"] ) ;
		$toolname = preg_replace ( '/\/.*$/' , '' , $toolname ) ;
	}
	if ( $toolname != '' ) {
		$sparql .= "#TOOL: $toolname\n" ;
	}

#	$sparql .= "PREFIX wdt: <http://www.wikidata.org/prop/direct/>\n" ;
#	$sparql .= "PREFIX wd: <http://www.wikidata.org/entity/>\n" ;
#	$sparql .= "PREFIX wikibase: <http://wikiba.se/ontology#>\n" ;
#	$sparql .= "PREFIX p: <http://www.wikidata.org/prop/>\n" ;
#	$sparql .= "PREFIX v: <http://www.wikidata.org/prop/statement/>\n" ;
#	$sparql .= "PREFIX q: <http://www.wikidata.org/prop/qualifier/>\n" ;
#	$sparql .= "PREFIX ps: <http://www.wikidata.org/prop/statement/>\n" ;
#	$sparql .= "PREFIX pq: <http://www.wikidata.org/prop/qualifier/>\n" ;
#	$sparql .= "PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>\n" ;
#	$sparql .= "PREFIX schema: <http://schema.org/>\n" ;
#	$sparql .= "PREFIX psv: <http://www.wikidata.org/prop/statement/value/>\n" ;
	return $sparql ;
}

function getSPARQL ( $cmd ) {
	$sparql = getSPARQLprefixes() ;
	$sparql .= $cmd ;

	$ctx = stream_context_create(array('http'=>
		array(
			'timeout' => 1200,  //1200 Seconds is 20 Minutes
		)
	));

	$url = "https://query.wikidata.org/sparql?format=json&query=" . urlencode($sparql) ;
#print "$url\n" ;
	$fc = @file_get_contents ( $url , false , $ctx ) ;
	
	// Catch "wait" response, wait 5, retry
	if ( preg_match ( '/429/' , $http_response_header[0] ) ) {
		sleep ( 5 ) ;
		return getSPARQL ( $cmd ) ;
	}

	if ( $fc === false ) return ; // Nope
	return json_decode ( $fc ) ;
}


function getSPARQLitems ( $cmd , $varname = 'q' ) {
	$ret = array() ;
	$j = getSPARQL ( $cmd ) ;
	if ( !isset($j->results) or !isset($j->results->bindings) or count($j->results->bindings) == 0 ) return $ret ;
	foreach ( $j->results->bindings AS $v ) {
		$ret[] = preg_replace ( '/^.+\/Q/' , '' , $v->$varname->value ) ;
	}
	$ret = array_unique ( $ret ) ;
	return $ret ;
}

?>
