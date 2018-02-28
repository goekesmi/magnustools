<?PHP

class MW_OAuth {

	var $use_cookies = 0 ;
	var $tool ;
	var $debugging = true ;
	var $language , $project ;
	var $ini_file , $params ;
	var $mwOAuthUrl = 'http://172.20.48.41/wiki/index.php?title=Special:OAuth';
	var $mwOAuthIW = 'mw'; // Set this to the interwiki prefix for the OAuth central wiki.
	var $userinfo ;
	
	function MW_OAuth ( $t , $l , $p ) {
		$this->tool = $t ;
		$this->language = $l ;
		$this->project = $p ;
		$this->ini_file = "/data/project/$t/oauth.ini" ;
		
		$this->apiUrl = "http://172.20.48.41/wiki/api.php" ;

		$this->loadIniFile() ;
		$this->setupSession() ;
		$this->loadToken() ;

		if ( isset( $_GET['oauth_verifier'] ) && $_GET['oauth_verifier'] ) {
			$this->fetchAccessToken();
		}

	}
	
	function logout () {
		$this->setupSession() ;
		session_start();
		setcookie ( 'tokenKey' , '' , 1 , '/'+$this->tool+'/' ) ;
		setcookie ( 'tokenSecret' , '' , 1 , '/'+$this->tool+'/' ) ;
		$_SESSION['tokenKey'] = '' ;
		$_SESSION['tokenSecret'] = '' ;
		session_write_close();
	}
	
	function setupSession() {
		// Setup the session cookie
		session_name( $this->tool );
		$params = session_get_cookie_params();
		session_set_cookie_params(
			$params['lifetime'],
			dirname( $_SERVER['SCRIPT_NAME'] )
		);
	}
	
	function loadIniFile () {
		$this->params = parse_ini_file ( $this->ini_file ) ;
		$this->gUserAgent = $this->params['agent'];
		$this->gConsumerKey = $this->params['consumerKey'];
		$this->gConsumerSecret = $this->params['consumerSecret'];
	}
	
	// Load the user token (request or access) from the session
	function loadToken() {
		$this->gTokenKey = '';
		$this->gTokenSecret = '';
		session_start();
		if ( isset( $_SESSION['tokenKey'] ) ) {
			$this->gTokenKey = $_SESSION['tokenKey'];
			$this->gTokenSecret = $_SESSION['tokenSecret'];
		} elseif ( $this->use_cookies and isset( $_COOKIE['tokenKey'] ) ) {
			$this->gTokenKey = $_COOKIE['tokenKey'];
			$this->gTokenSecret = $_COOKIE['tokenSecret'];
		}
		session_write_close();
	}


	/**
	 * Handle a callback to fetch the access token
	 * @return void
	 */
	function fetchAccessToken() {
		$url = $this->mwOAuthUrl . '/token';
		$url .= strpos( $url, '?' ) ? '&' : '?';
		$url .= http_build_query( array(
			'format' => 'json',
			'oauth_verifier' => $_GET['oauth_verifier'],

			// OAuth information
			'oauth_consumer_key' => $this->gConsumerKey,
			'oauth_token' => $this->gTokenKey,
			'oauth_version' => '1.0',
			'oauth_nonce' => md5( microtime() . mt_rand() ),
			'oauth_timestamp' => time(),

			// We're using secret key signatures here.
			'oauth_signature_method' => 'HMAC-SHA1',
		) );
		$this->signature = $this->sign_request( 'GET', $url );
		$url .= "&oauth_signature=" . urlencode( $this->signature );
		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
		curl_setopt( $ch, CURLOPT_USERAGENT, $this->gUserAgent );
		curl_setopt( $ch, CURLOPT_HEADER, 0 );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
		$data = curl_exec( $ch );

		if ( isset ( $_REQUEST['test'] ) ) {
			print "<h1>LOGIN</h1><pre>" ; print_r ( $data ) ; print "</pre></hr>" ;
		}

		if ( !$data ) {
			header( "HTTP/1.1 500 Internal Server Error" );
			echo 'Curl error: ' . htmlspecialchars( curl_error( $ch ) );
			exit(0);
		}
		curl_close( $ch );
		$token = json_decode( $data );
		if ( is_object( $token ) && isset( $token->error ) ) {
			header( "HTTP/1.1 500 Internal Server Error" );
			echo 'Error retrieving token: ' . htmlspecialchars( $token->error );
			exit(0);
		}
		if ( !is_object( $token ) || !isset( $token->key ) || !isset( $token->secret ) ) {
			header( "HTTP/1.1 500 Internal Server Error" );
			echo 'Invalid response from token request';
			exit(0);
		}

		// Save the access token
		session_start();
		$_SESSION['tokenKey'] = $this->gTokenKey = $token->key;
		$_SESSION['tokenSecret'] = $this->gTokenSecret = $token->secret;
		if ( $this->use_cookies ) {
			$t = time()+60*60*24*30 ; // expires in one month
			setcookie ( 'tokenKey' , $_SESSION['tokenKey'] , $t , '/'+$this->tool+'/' ) ;
			setcookie ( 'tokenSecret' , $_SESSION['tokenSecret'] , $t , '/'+$this->tool+'/' ) ;
		}
		session_write_close();
	}


	/**
	 * Utility function to sign a request
	 *
	 * Note this doesn't properly handle the case where a parameter is set both in 
	 * the query string in $url and in $params, or non-scalar values in $params.
	 *
	 * @param string $method Generally "GET" or "POST"
	 * @param string $url URL string
	 * @param array $params Extra parameters for the Authorization header or post 
	 * 	data (if application/x-www-form-urlencoded).
	 *Â @return string Signature
	 */
	function sign_request( $method, $url, $params = array() ) {
//		global $gConsumerSecret, $gTokenSecret;

		$parts = parse_url( $url );

		// We need to normalize the endpoint URL
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'http';
		$host = isset( $parts['host'] ) ? $parts['host'] : '';
		$port = isset( $parts['port'] ) ? $parts['port'] : ( $scheme == 'https' ? '443' : '80' );
		$path = isset( $parts['path'] ) ? $parts['path'] : '';
		if ( ( $scheme == 'https' && $port != '443' ) ||
			( $scheme == 'http' && $port != '80' ) 
		) {
			// Only include the port if it's not the default
			$host = "$host:$port";
		}

		// Also the parameters
		$pairs = array();
		parse_str( isset( $parts['query'] ) ? $parts['query'] : '', $query );
		$query += $params;
		unset( $query['oauth_signature'] );
		if ( $query ) {
			$query = array_combine(
				// rawurlencode follows RFC 3986 since PHP 5.3
				array_map( 'rawurlencode', array_keys( $query ) ),
				array_map( 'rawurlencode', array_values( $query ) )
			);
			ksort( $query, SORT_STRING );
			foreach ( $query as $k => $v ) {
				$pairs[] = "$k=$v";
			}
		}

		$toSign = rawurlencode( strtoupper( $method ) ) . '&' .
			rawurlencode( "$scheme://$host$path" ) . '&' .
			rawurlencode( join( '&', $pairs ) );
		$key = rawurlencode( $this->gConsumerSecret ) . '&' . rawurlencode( $this->gTokenSecret );
		return base64_encode( hash_hmac( 'sha1', $toSign, $key, true ) );
	}

	/**
	 * Request authorization
	 * @return void
	 */
	function doAuthorizationRedirect($callback='') {
		// First, we need to fetch a request token.
		// The request is signed with an empty token secret and no token key.
		$this->gTokenSecret = '';
		$url = $this->mwOAuthUrl . '/initiate';
		$url .= strpos( $url, '?' ) ? '&' : '?';
		$query = array(
			'format' => 'json',
		
			// OAuth information
			'oauth_callback' => 'oob', // Must be "oob" for MWOAuth
			'oauth_consumer_key' => $this->gConsumerKey,
			'oauth_version' => '1.0',
			'oauth_nonce' => md5( microtime() . mt_rand() ),
			'oauth_timestamp' => time(),

			// We're using secret key signatures here.
			'oauth_signature_method' => 'HMAC-SHA1',
		) ;
		if ( $callback!='' ) $query['callback'] = $callback ;
		$url .= http_build_query( $query );
		$signature = $this->sign_request( 'GET', $url );
		$url .= "&oauth_signature=" . urlencode( $signature );
		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $url );
		//curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
		curl_setopt( $ch, CURLOPT_USERAGENT, $this->gUserAgent );
		curl_setopt( $ch, CURLOPT_HEADER, 0 );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
		$data = curl_exec( $ch );
		if ( !$data ) {
			header( "HTTP/1.1 500 Internal Server Error" );
			echo 'Curl error: ' . htmlspecialchars( curl_error( $ch ) );
			exit(0);
		}
		curl_close( $ch );
		$token = json_decode( $data );
		if ( $token === NULL ) {
			print_r ( $data ) ; exit ( 0 ) ; // SHOW MEDIAWIKI ERROR
		}
		if ( is_object( $token ) && isset( $token->error ) ) {
			header( "HTTP/1.1 500 Internal Server Error" );
			echo 'Error retrieving token: ' . htmlspecialchars( $token->error );
			exit(0);
		}
		if ( !is_object( $token ) || !isset( $token->key ) || !isset( $token->secret ) ) {
			header( "HTTP/1.1 500 Internal Server Error" );
			echo 'Invalid response from token request';
			exit(0);
		}

		// Now we have the request token, we need to save it for later.
		session_start();
		$_SESSION['tokenKey'] = $token->key;
		$_SESSION['tokenSecret'] = $token->secret;
		if ( $this->use_cookies ) {
			$t = time()+60*60*24*30 ; // expires in one month
			setcookie ( 'tokenKey' , $_SESSION['tokenKey'] , $t , '/'+$this->tool+'/' ) ;
			setcookie ( 'tokenSecret' , $_SESSION['tokenSecret'] , $t , '/'+$this->tool+'/' ) ;
		}
		session_write_close();

		// Then we send the user off to authorize
		$url = $this->mwOAuthUrl . '/authorize';
		$url .= strpos( $url, '?' ) ? '&' : '?';
		$arr = array(
			'oauth_token' => $token->key,
			'oauth_consumer_key' => $this->gConsumerKey,
		) ;
		if ( $callback != '' ) $arr['callback'] = $callback ;
		$url .= http_build_query( $arr );
		header( "Location: $url" );
		echo 'Please see <a href="' . htmlspecialchars( $url ) . '">' . htmlspecialchars( $url ) . '</a>';
	}


	function doIdentify() {

		$url = $this->mwOAuthUrl . '/identify';
		$headerArr = array(
			// OAuth information
			'oauth_consumer_key' => $this->gConsumerKey,
			'oauth_token' => $this->gTokenKey,
			'oauth_version' => '1.0',
			'oauth_nonce' => md5( microtime() . mt_rand() ),
			'oauth_timestamp' => time(),

			// We're using secret key signatures here.
			'oauth_signature_method' => 'HMAC-SHA1',
		);
		$signature = $this->sign_request( 'GET', $url, $headerArr );
		$headerArr['oauth_signature'] = $signature;

		$header = array();
		foreach ( $headerArr as $k => $v ) {
			$header[] = rawurlencode( $k ) . '="' . rawurlencode( $v ) . '"';
		}
		$header = 'Authorization: OAuth ' . join( ', ', $header );

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array( $header ) );
		//curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
		curl_setopt( $ch, CURLOPT_USERAGENT, $this->gUserAgent );
		curl_setopt( $ch, CURLOPT_HEADER, 0 );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
		$data = curl_exec( $ch );
		if ( !$data ) {
			header( "HTTP/1.1 $errorCode Internal Server Error" );
			echo 'Curl error: ' . htmlspecialchars( curl_error( $ch ) );
			exit(0);
		}
		$err = json_decode( $data );
		if ( is_object( $err ) && isset( $err->error ) && $err->error === 'mwoauthdatastore-access-token-not-found' ) {
			// We're not authorized!
#			echo 'You haven\'t authorized this application yet! Go <a href="' . htmlspecialchars( $_SERVER['SCRIPT_NAME'] ) . '?action=authorize">here</a> to do that.';
#			echo '<hr>';
			return (object) array('is_authorized'=>false) ;
		}
		
		// There are three fields in the response
		$fields = explode( '.', $data );
		if ( count( $fields ) !== 3 ) {
			header( "HTTP/1.1 $errorCode Internal Server Error" );
			echo 'Invalid identify response: ' . htmlspecialchars( $data );
			exit(0);
		}

		// Validate the header. MWOAuth always returns alg "HS256".
		$header = base64_decode( strtr( $fields[0], '-_', '+/' ), true );
		if ( $header !== false ) {
			$header = json_decode( $header );
		}
		if ( !is_object( $header ) || $header->typ !== 'JWT' || $header->alg !== 'HS256' ) {
			header( "HTTP/1.1 $errorCode Internal Server Error" );
			echo 'Invalid header in identify response: ' . htmlspecialchars( $data );
			exit(0);
		}

		// Verify the signature
		$sig = base64_decode( strtr( $fields[2], '-_', '+/' ), true );
		$check = hash_hmac( 'sha256', $fields[0] . '.' . $fields[1], $this->gConsumerSecret, true );
		if ( $sig !== $check ) {
			header( "HTTP/1.1 $errorCode Internal Server Error" );
			echo 'JWT signature validation failed: ' . htmlspecialchars( $data );
			echo '<pre>'; var_dump( base64_encode($sig), base64_encode($check) ); echo '</pre>';
			exit(0);
		}

		// Decode the payload
		$payload = base64_decode( strtr( $fields[1], '-_', '+/' ), true );
		if ( $payload !== false ) {
			$payload = json_decode( $payload );
		}
		if ( !is_object( $payload ) ) {
			header( "HTTP/1.1 $errorCode Internal Server Error" );
			echo 'Invalid payload in identify response: ' . htmlspecialchars( $data );
			exit(0);
		}
		
		$payload->is_authorized = true ;
		return $payload ;
	}



	/**
	 * Send an API query with OAuth authorization
	 *
	 * @param array $post Post data
	 * @param object $ch Curl handle
	 * @return array API results
	 */
	function doApiQuery( $post, &$ch = null , $mode = '' ) {
		global $maxlag ;
		if ( !isset($maxlag) ) $maxlag = 5 ;
		$post['maxlag'] = $maxlag ;
		
		$headerArr = array(
			// OAuth information
			'oauth_consumer_key' => $this->gConsumerKey,
			'oauth_token' => $this->gTokenKey,
			'oauth_version' => '1.0',
			'oauth_nonce' => md5( microtime() . mt_rand() ),
			'oauth_timestamp' => time(),

			// We're using secret key signatures here.
			'oauth_signature_method' => 'HMAC-SHA1',
		);

		if ( isset ( $_REQUEST['test'] ) ) {
			print "<pre>" ;
			print "!!\n" ;
//			print_r ( $headerArr ) ;
			print "</pre>" ;
		}
		
		$to_sign = '' ;
		if ( $mode == 'upload' ) {
			$to_sign = $headerArr ;
		} else {
			$to_sign = $post + $headerArr ;
		}
		$url = $this->apiUrl ;
		if ( $mode == 'identify' ) $url .= '/identify' ;
		$signature = $this->sign_request( 'POST', $url, $to_sign );
		$headerArr['oauth_signature'] = $signature;

		$header = array();
		foreach ( $headerArr as $k => $v ) {
			$header[] = rawurlencode( $k ) . '="' . rawurlencode( $v ) . '"';
		}
		$header = 'Authorization: OAuth ' . join( ', ', $header );


		if ( !$ch ) {
			$ch = curl_init();
			
		}
		
		$post_fields = '' ;
		if ( $mode == 'upload' ) {
			$post_fields = $post ;
			$post_fields['file'] = new CurlFile($post['file'], 'application/octet-stream', $post['filename']);
		} else {
			$post_fields = http_build_query( $post ) ;
		}
		
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $post_fields );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array( $header ) );
		//curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
		curl_setopt( $ch, CURLOPT_USERAGENT, $this->gUserAgent );
		curl_setopt( $ch, CURLOPT_HEADER, 0 );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );

		$data = curl_exec( $ch );

		if ( isset ( $_REQUEST['test'] ) ) {
			print "<hr/><h3>API query</h3>" ;
//			print "URL:<pre>$url</pre>" ;
//			print "Header:<pre>" ; print_r ( $header ) ; print "</pre>" ;
			print "Payload:<pre>" ; print_r ( $post ) ; print "</pre>" ;
			print "Result:<pre>" ; print_r ( $data ) ; print "</pre>" ;
			print "<hr/>" ;
		}


		if ( isset($_REQUEST['test']) ) {
			print "RESULT:<hr/>" ;
			print_r ( $data ) ;
			print "<hr/>" ;
		}

		if ( !$data ) {
		return ;
//			if ( $mode != 'userinfo' ) header( "HTTP/1.1 500 Internal Server Error" );
			$info = curl_getinfo($ch);
			print "<pre>" ; print_r ( $info ) ; print "</pre>" ;
			echo 'Curl error: ' . htmlspecialchars( curl_error( $ch ) );
			exit(0);
		}
		$ret = json_decode( $data );
		if ( $ret == null ) {
		return ;
//			if ( $mode != 'userinfo' ) header( "HTTP/1.1 500 Internal Server Error" );
			print "<h1>API trouble!</h1>" ;
//			print "<pre>" ; print_r ($header ) ; print "</pre>" ;
			print "<pre>" ; print_r ($post ) ; print "</pre>" ;
			print "<pre>" ; print_r ($data ) ; print "</pre>" ;
			print "<pre>" ; print var_export ( $ch , 1 ) ; print "</pre>" ;
			exit(0);
		}
		
		# maxlag
		if ( isset($ret->error) and isset($ret->error->code) and $ret->error->code == 'maxlag' ) {
			sleep ( $maxlag ) ;
			$ch = null ;
			$ret = $this->doApiQuery( $post, $ch , '' ) ;
		}
		
		return $ret ;
	}




	// Wikidata-specific methods
	
/*
Claims are used like this:
	$claim = array (
		"prop" => 'P31' ,
		"q" => 'Q4115189' ,
		"target" => 'Q12345' ,
		"type" => "item"
	) ;
*/
	
	function doesClaimExist ( $claim ) {
		$q = 'Q' . str_replace('Q','',$claim['q'].'') ;
		$p = 'P' . str_replace('P','',$claim['prop'].'') ;
		$url = 'http://172.20.48.41/wiki/api.php?action=wbgetentities&format=json&props=claims&ids=' . $q ;
		$j = json_decode ( file_get_contents ( $url ) ) ;
	//	print "<pre>" ; print_r ( $j ) ; print "</pre>" ;

		if ( !isset ( $j->entities ) ) return false ;
		if ( !isset ( $j->entities->$q ) ) return false ;
		if ( !isset ( $j->entities->$q->claims ) ) return false ;
		if ( !isset ( $j->entities->$q->claims->$p ) ) return false ;

		$nid = 'numeric-id' ;
		$does_exist = false ;
		$cp = $j->entities->$q->claims->$p ; // Claims for this property
		foreach ( $cp AS $k => $v ) {
	//		print "<pre>" ; print_r ( $v ) ; print "</pre>" ;
			if ( $claim['type'] == 'item' ) {
				if ( !isset($v->mainsnak) ) continue ;
				if ( !isset($v->mainsnak->datavalue) ) continue ;
				if ( !isset($v->mainsnak->datavalue->value) ) continue ;
				if ( $v->mainsnak->datavalue->value->$nid != str_replace('Q','',$claim['target'].'') ) continue ;
				$does_exist = true ;
			} elseif ( $claim['type'] == 'string' ) {
				if ( !isset($v->mainsnak) ) continue ;
				if ( !isset($v->mainsnak->datavalue) ) continue ;
				if ( !isset($v->mainsnak->datavalue->value) ) continue ;
				if ( $v->mainsnak->datavalue->value != $claim['text'] ) continue ;
				$does_exist = true ;
			} elseif ( $claim['type'] == 'date' ) {
				if ( !isset($v->mainsnak) ) continue ;
				if ( !isset($v->mainsnak->datavalue) ) continue ;
				if ( !isset($v->mainsnak->datavalue->value) ) continue ;
				if ( !isset($v->mainsnak->datavalue->value->time) ) continue ;
				if ( $v->mainsnak->datavalue->value->time != $claim['date'] ) continue ;
				if ( $v->mainsnak->datavalue->value->precision != $claim['prec'] ) continue ;
				$does_exist = true ;
			} else if ( $claim['type'] == 'monolingualtext' ) {
				if ( !isset($v->mainsnak) ) continue ;
				if ( !isset($v->mainsnak->datavalue) ) continue ;
				if ( !isset($v->mainsnak->datavalue->value) ) continue ;
				if ( !isset($v->mainsnak->datavalue->value->text) ) continue ;
				if ( $v->mainsnak->datavalue->value->text != $claim['text'] ) continue ;
				if ( $v->mainsnak->datavalue->value->language != $claim['language'] ) continue ;
				$does_exist = true ;
			} else if ( $claim['type'] == 'quantity' ) {
				if ( !isset($v->mainsnak) ) continue ;
				if ( !isset($v->mainsnak->datavalue) ) continue ;
				if ( !isset($v->mainsnak->datavalue->value) ) continue ;
				if ( !isset($v->mainsnak->datavalue->value->amount) ) continue ;
				if ( $v->mainsnak->datavalue->value->amount != $claim['amount'] ) continue ;
				if ( $v->mainsnak->datavalue->value->unit != $claim['unit'] ) continue ;
				$does_exist = true ;
			}
		}
	
		return $does_exist ;
	}


	function getConsumerRights () {

		// Next fetch the edit token
		$ch = null;
		$res = $this->doApiQuery( array(
			'format' => 'json',
			'action' => 'query',
			'meta' => 'userinfo',
			'uiprop' => 'blockinfo|groups|rights'
		), $ch );
		
		return $res ;
	}

	
	function setLabel ( $q , $text , $language ) {

		// Fetch the edit token
		$ch = null;
		$res = $this->doApiQuery( array(
			'format' => 'json',
			'action' => 'query' ,
			'meta' => 'tokens'
		), $ch );
		if ( !isset( $res->query->tokens->csrftoken ) ) {
			$this->error = 'Bad API response [setLabel]: <pre>' . htmlspecialchars( var_export( $res, 1 ) ) . '</pre>';
			return false ;
		}
		$token = $res->query->tokens->csrftoken;

		$params = array(
			'format' => 'json',
			'action' => 'wbsetlabel',
			'id' => $q,
			'language' => $language ,
			'value' => $text ,
			'token' => $token,
			'bot' => 1
		) ;

		global $tool_hashtag ;
		if ( isset($tool_hashtag) and $tool_hashtag != '' ) $summary = isset($summary) ? trim("$summary #$tool_hashtag") : "#$tool_hashtag" ;
		if ( isset($summary) and $summary != '' ) $params['summary'] = $summary ;

		// Now do that!
		$res = $this->doApiQuery( $params , $ch );
		
		if ( isset ( $res->error ) ) {
			$this->error = $res->error->info ;
			return false ;
		}

		return true ;
	}
	
	
	function setSitelink ( $q , $site , $title ) {

		// Fetch the edit token
		$ch = null;
		$res = $this->doApiQuery( array(
			'format' => 'json',
			'action' => 'query' ,
			'meta' => 'tokens'
		), $ch );
		if ( !isset( $res->query->tokens->csrftoken ) ) {
			$this->error = 'Bad API response [setLabel]: <pre>' . htmlspecialchars( var_export( $res, 1 ) ) . '</pre>';
			return false ;
		}
		$token = $res->query->tokens->csrftoken;

		$params = array(
			'format' => 'json',
			'action' => 'wbsetsitelink',
			'id' => $q,
			'linksite' => $site,
			'linktitle' => $title,
			'token' => $token,
			'bot' => 1
		) ;

		global $tool_hashtag ;
		if ( isset($tool_hashtag) and $tool_hashtag != '' ) $summary = isset($summary) ? trim("$summary #$tool_hashtag") : "#$tool_hashtag" ;
		if ( isset($summary) and $summary != '' ) $params['summary'] = $summary ;

		// Now do that!
		$res = $this->doApiQuery( $params , $ch );
		
		$this->last_res = $res ;
		if ( isset ( $res->error ) ) {
			$this->error = $res->error->info ;
			return false ;
		}

		return true ;
	}
	
	
	function setDesc ( $q , $text , $language ) {

		// Fetch the edit token
		$ch = null;
		$res = $this->doApiQuery( array(
			'format' => 'json',
			'action' => 'query' ,
			'meta' => 'tokens'
		), $ch );
		if ( !isset( $res->query->tokens->csrftoken ) ) {
			$this->error = 'Bad API response [setLabel]: <pre>' . htmlspecialchars( var_export( $res, 1 ) ) . '</pre>';
			return false ;
		}
		$token = $res->query->tokens->csrftoken;

		$params = array(
			'format' => 'json',
			'action' => 'wbsetdescription',
			'id' => $q,
			'language' => $language ,
			'value' => $text ,
			'token' => $token,
			'bot' => 1
		) ;

		global $tool_hashtag ;
		if ( isset($tool_hashtag) and $tool_hashtag != '' ) $summary = isset($summary) ? trim("$summary #$tool_hashtag") : "#$tool_hashtag" ;
		if ( isset($summary) and $summary != '' ) $params['summary'] = $summary ;

		// Now do that!
		$res = $this->doApiQuery( $params , $ch );
		
		if ( isset ( $res->error ) ) {
			$this->error = $res->error->info ;
			return false ;
		}

		return true ;
	}
	
	
	function set_Alias ( $q , $text , $language , $mode ) {

		// Fetch the edit token
		$ch = null;
		$res = $this->doApiQuery( array(
			'format' => 'json',
			'action' => 'query' ,
			'meta' => 'tokens'
		), $ch );
		if ( !isset( $res->query->tokens->csrftoken ) ) {
			$this->error = 'Bad API response [setLabel]: <pre>' . htmlspecialchars( var_export( $res, 1 ) ) . '</pre>';
			return false ;
		}
		$token = $res->query->tokens->csrftoken;

		$params = array(
			'format' => 'json',
			'action' => 'wbsetaliases',
			$mode => $text ,
			'id' => $q,
			'language' => $language ,
//			'value' => $text ,
			'token' => $token,
			'bot' => 1
		) ;

		global $tool_hashtag ;
		if ( isset($tool_hashtag) and $tool_hashtag != '' ) $summary = isset($summary) ? trim("$summary #$tool_hashtag") : "#$tool_hashtag" ;
		if ( isset($summary) and $summary != '' ) $params['summary'] = $summary ;

		// Now do that!
		$res = $this->doApiQuery( $params , $ch );
		
		if ( isset ( $res->error ) ) {
			$this->error = $res->error->info ;
			return false ;
		}

		return true ;
	}
	
	
	function setPageText ( $page , $text ) {

		// Fetch the edit token
		$ch = null;
		$res = $this->doApiQuery( array(
			'format' => 'json',
			'action' => 'query' ,
			'meta' => 'tokens'
		), $ch );
		if ( !isset( $res->query->tokens->csrftoken ) ) {
			$this->error = 'Bad API response [setPageText]: <pre>' . htmlspecialchars( var_export( $res, 1 ) ) . '</pre>';
			return false ;
		}
		$token = $res->query->tokens->csrftoken;

		$params = array(
			'format' => 'json',
			'action' => 'edit',
			'title' => $page,
			'text' => $text ,
			'minor' => '' ,
			'token' => $token,
		) ;
		
		global $tool_hashtag ;
		if ( isset($tool_hashtag) and $tool_hashtag != '' ) $summary = isset($summary) ? trim("$summary #$tool_hashtag") : "#$tool_hashtag" ;
		if ( isset($summary) and $summary != '' ) $params['summary'] = $summary ;

		
		// Now do that!
		$res = $this->doApiQuery( $params, $ch );
		
		if ( isset ( $res->error ) ) {
			$this->error = $res->error->info ;
			return false ;
		}

		return true ;
	}
	
	function addPageText ( $page , $text , $header , $summary , $section ) {

		// Fetch the edit token
		$ch = null;
		$res = $this->doApiQuery( array(
			'format' => 'json',
			'action' => 'query' ,
			'meta' => 'tokens'
		), $ch );
		if ( !isset( $res->query->tokens->csrftoken ) ) {
			$this->error = 'Bad API response [setPageText]: <pre>' . htmlspecialchars( var_export( $res, 1 ) ) . '</pre>';
			return false ;
		}
		$token = $res->query->tokens->csrftoken;
		
		$p = array(
			'format' => 'json',
			'action' => 'edit',
			'title' => $page,
			'appendtext' => $text ,
			'sectiontitle' => $header ,
			'minor' => '' ,
			'token' => $token,
		) ;
		
		if ( isset ( $section ) and $section != '' ) $p['section'] = $section ;

		global $tool_hashtag ;
		if ( isset($tool_hashtag) and $tool_hashtag != '' ) $summary = isset($summary) ? trim("$summary #$tool_hashtag") : "#$tool_hashtag" ;
		if ( isset($summary) and $summary != '' ) $params['summary'] = $summary ;
		
		// Now do that!
		$res = $this->doApiQuery( $p , $ch );
		
		if ( isset ( $res->error ) ) {
			$this->error = $res->error->info ;
			return false ;
		}

		return true ;
	}
	
	function createItem ( $data = '' ) {
	
		if ( $data == '' ) $data = (object) array() ;
	
		// Next fetch the edit token
		$ch = null;
		$res = $this->doApiQuery( array(
			'format' => 'json',
			'action' => 'query' ,
			'meta' => 'tokens'
		), $ch );
		if ( !isset( $res->query->tokens->csrftoken ) ) {
			$this->error = 'Bad API response [createItem]: <pre>' . htmlspecialchars( var_export( $res, 1 ) ) . '</pre>';
			return false ;
		}
		$token = $res->query->tokens->csrftoken;


		$params = array(
			'format' => 'json',
			'action' => 'wbeditentity',
			'new' => 'item' ,
			'data' => json_encode ( $data ) ,
			'token' => $token,
			'bot' => 1
		) ;
		
		global $tool_hashtag ;
		if ( isset($tool_hashtag) and $tool_hashtag != '' ) $summary = isset($summary) ? trim("$summary #$tool_hashtag") : "#$tool_hashtag" ;
		if ( isset($summary) and $summary != '' ) $params['summary'] = $summary ;

		$res = $this->doApiQuery( $params , $ch );
		
		if ( isset ( $_REQUEST['test'] ) ) {
			print "<pre>" ; print_r ( $res ) ; print "</pre>" ;
		}

		$this->last_res = $res ;
		if ( isset ( $res->error ) ) return false ;

		return true ;
	}

	function createItemFromPage ( $site , $page ) {
		$page = str_replace ( ' ' , '_' , $page ) ;
	
		// Next fetch the edit token
		$ch = null;
		$res = $this->doApiQuery( array(
			'format' => 'json',
			'action' => 'query' ,
			'meta' => 'tokens'
		), $ch );
		if ( !isset( $res->query->tokens->csrftoken ) ) {
			$this->error = 'Bad API response [createItemFromPage]: <pre>' . htmlspecialchars( var_export( $res, 1 ) ) . '</pre>';
			return false ;
		}
		$token = $res->query->tokens->csrftoken;


		$data = array ( 
			'sitelinks' => array ( $site => array ( "site" => $site ,"title" => $page ) )
		) ;
		$m = array () ;
		if ( preg_match ( '/^(.+)wiki$/' , $site , $m ) ) {
			$nice_title = preg_replace ( '/\s+\(.+$/' , '' , str_replace ( '_' , ' ' , $page ) ) ;
			$lang = $m[1] ;
			if ( $lang == 'species' or $lang == 'meta' or $lang == 'wikidata' ) $lang = 'en' ; // Default language
			if ( $lang == 'no' ) $lang = 'nb' ;
			$data['labels'] = array ( $lang => array ( 'language' => $lang , 'value' => $nice_title ) ) ;
		}
//		print "<pre>" ; print_r ( json_encode ( $data ) ) ; print " </pre>" ; return true ;

		$params = array(
			'format' => 'json',
			'action' => 'wbeditentity',
			'new' => 'item' ,
			'data' => json_encode ( $data ) ,
			'token' => $token,
			'bot' => 1
		) ;
		
		global $tool_hashtag ;
		if ( isset($tool_hashtag) and $tool_hashtag != '' ) $summary = isset($summary) ? trim("$summary #$tool_hashtag") : "#$tool_hashtag" ;
		if ( isset($summary) and $summary != '' ) $params['summary'] = $summary ;

		if ( isset ( $_REQUEST['test'] ) ) {
			print "<pre>" ; print_r ( $params ) ; print "</pre>" ;
		}
		
		$res = $this->doApiQuery( $params , $ch );

		
		if ( isset ( $_REQUEST['test'] ) ) {
			print "<pre>" ; print_r ( $res ) ; print "</pre>" ;
		}

		$this->last_res = $res ;
		if ( isset ( $res->error ) ) return false ;

		return true ;
	}

	function removeClaim ( $id , $baserev ) {
		// Fetch the edit token
		$ch = null;
		$res = $this->doApiQuery( array(
			'format' => 'json',
			'action' => 'query' ,
			'meta' => 'tokens'
		), $ch );
		if ( !isset( $res->query->tokens->csrftoken ) ) {
			$this->error = 'Bad API response [removeClaim]: <pre>' . htmlspecialchars( var_export( $res, 1 ) ) . '</pre>';
			return false ;
		}
		$token = $res->query->tokens->csrftoken;
	
	
	
		// Now do that!
		$params = array(
			'format' => 'json',
			'action' => 'wbremoveclaims',
			'claim' => $id ,
			'token' => $token,
			'bot' => 1
		) ;
		if ( isset ( $baserev ) and $baserev != '' ) $params['baserevid'] = $baserev ;

		global $tool_hashtag ;
		if ( isset($tool_hashtag) and $tool_hashtag != '' ) $summary = isset($summary) ? trim("$summary #$tool_hashtag") : "#$tool_hashtag" ;
		if ( isset($summary) and $summary != '' ) $params['summary'] = $summary ;


		$res = $this->doApiQuery( $params , $ch );
		
		if ( isset ( $_REQUEST['test'] ) ) {
			print "<pre>" ; print_r ( $claim ) ; print "</pre>" ;
			print "<pre>" ; print_r ( $res ) ; print "</pre>" ;
		}
		
		return true ;
	}
	
	
	
	
	
	
	function setSource ( $statement , $snaks_json ) {

		// Next fetch the edit token
		$ch = null;
		$res = $this->doApiQuery( array(
			'format' => 'json',
			'action' => 'query' ,
			'meta' => 'tokens'
		), $ch );
		if ( !isset( $res->query->tokens->csrftoken ) ) {
			$this->error = 'Bad API response [setSource]: <pre>' . htmlspecialchars( var_export( $res, 1 ) ) . '</pre>';
			return false ;
		}
		$token = $res->query->tokens->csrftoken;

		$params = array(
			'format' => 'json',
			'action' => 'wbsetreference',
			'statement' => $statement ,
			'snaks' => $snaks_json ,
			'token' => $token,
			'bot' => 1
		) ;

		global $tool_hashtag ;
		if ( isset($tool_hashtag) and $tool_hashtag != '' ) $summary = isset($summary) ? trim("$summary #$tool_hashtag") : "#$tool_hashtag" ;
		if ( isset($summary) and $summary != '' ) $params['summary'] = $summary ;
		
		// TODO : baserevid

		$res = $this->doApiQuery( $params, $ch );
		
		if ( isset ( $_REQUEST['test'] ) ) {
			print "<pre>" ; print_r ( $claim ) ; print "</pre>" ;
			print "<pre>" ; print_r ( $res ) ; print "</pre>" ;
		}

		$this->last_res = $res ;
		if ( isset ( $res->error ) ) {
			if ( $res->error->code == 'modification-failed' ) return true ; // Already exists, no real error
			return false ;
		}

		return true ;

	}


	
	function createRedirect ( $from , $to ) {
		# No summary option!
	
		// Next fetch the edit token
		$ch = null;
		$res = $this->doApiQuery( array(
			'format' => 'json',
			'action' => 'query' ,
			'meta' => 'tokens'
		), $ch );
		if ( !isset( $res->query->tokens->csrftoken ) ) {
			$this->error = 'Bad API response [createRedirect]: <pre>' . htmlspecialchars( var_export( $res, 1 ) ) . '</pre>';
			return false ;
		}
		$token = $res->query->tokens->csrftoken;

		$params = array(
			'format' => 'json',
			'action' => 'wbcreateredirect',
			'from' => $from ,
			'to' => $to ,
			'token' => $token,
			'bot' => 1
		) ;

		$res = $this->doApiQuery( $params, $ch );
		
		if ( isset ( $_REQUEST['test'] ) ) {
			print "<pre>" ; print_r ( $res ) ; print "</pre>" ;
		}

		$this->last_res = $res ;
		if ( isset ( $res->error ) ) return false ;

		return true ;
	}


	function genericAction ( $j , $summary = '' ) {
		if ( !isset($j->action) ) { // Paranoia
			$this->error = "No action in " . json_encode ( $j ) ;
			return false ;
		}
		
		
		// Next fetch the edit token
		$ch = null;
		$res = $this->doApiQuery( array(
			'format' => 'json',
			'action' => 'query' ,
			'meta' => 'tokens'
		), $ch );
		if ( !isset( $res->query->tokens->csrftoken ) ) {
			$this->error = 'Bad API response [genericAction]: <pre>' . htmlspecialchars( var_export( $res, 1 ) ) . '</pre>';
			return false ;
		}

		$j->token = $res->query->tokens->csrftoken;
		$j->format = 'json' ;
		$j->bot = 1 ;
		
		$params = array() ;
		foreach ( $j AS $k => $v ) $params[$k] = $v ;


		global $tool_hashtag ;
		if ( isset($tool_hashtag) and $tool_hashtag != '' ) $summary = ($summary!='') ? trim("$summary #$tool_hashtag") : "#$tool_hashtag" ;
		if ( $summary != '' ) $params['summary'] = $summary ;
		
		if ( isset ( $_REQUEST['test'] ) ) {
			print "!!!!!<pre>" ; print_r ( $params ) ; print "</pre>" ;
		}

		$res = $this->doApiQuery( $params, $ch );
		
		if ( isset ( $_REQUEST['test'] ) ) {
			print "<pre>" ; print_r ( $claim ) ; print "</pre>" ;
			print "<pre>" ; print_r ( $res ) ; print "</pre>" ;
		}

		$this->last_res = $res ;
		if ( isset ( $res->error ) ) {
			$this->error = $res->error->info ;
			return false ;
		}
		
		return true ;
	}


	function setClaim ( $claim , $summary = '' ) {
		if ( !isset ( $claim['claim'] ) ) { // Only for non-qualifier action; should that be fixed?
			if ( $this->doesClaimExist($claim) ) return true ;
		}

		// Next fetch the edit token
		$ch = null;
		$res = $this->doApiQuery( array(
			'format' => 'json',
			'action' => 'query' ,
			'meta' => 'tokens'
		), $ch );
		if ( !isset( $res->query->tokens->csrftoken ) ) {
			$this->error = 'Bad API response [setClaim]: <pre>' . htmlspecialchars( var_export( $res, 1 ) ) . '</pre>';
			return false ;
		}
		$token = $res->query->tokens->csrftoken;
	
//		if ( $claim['amount'] > 0 ) $claim['amount'] = '+'.$claim['amount'] ;
//		if ( $claim['upper'] > 0 ) $claim['upper'] = '+'.$claim['upper'] ;
//		if ( $claim['lower'] > 0 ) $claim['lower'] = '+'.$claim['lower'] ;
	
		// Now do that!
		$value = "" ;
		if ( $claim['type'] == 'item' ) {
			$value = '{"entity-type":"item","numeric-id":'.str_replace('Q','',$claim['target'].'').'}' ;
		} elseif ( $claim['type'] == 'string' ) {
			$value = json_encode($claim['text']) ;
//			$value = '{"type":"string","value":'.json_encode($claim['text']).'}' ;
		} elseif ( $claim['type'] == 'date' ) {
			$value = '{"time":"'.$claim['date'].'","timezone": 0,"before": 0,"after": 0,"precision": '.$claim['prec'].',"calendarmodel": "http://www.wikidata.org/entity/Q1985727"}' ;
		} else if ( $claim['type'] == 'location' ) {
			$value = '{"latitude":'.$claim['lat'].',"precision":0.000001,"longitude": '.$claim['lon'].',"globe": "http://www.wikidata.org/entity/Q2"}' ;
		} else if ( $claim['type'] == 'quantity' ) {
			$value = '{"amount":'.$claim['amount'].',"unit": "'.$claim['unit'].'","upperBound":'.$claim['upper'].',"lowerBound":'.$claim['lower'].'}' ;
		} else if ( $claim['type'] == 'monolingualtext' ) {
			$value = '{"text":' . json_encode($claim['text']) . ',"language":' . json_encode($claim['language']) . '}' ;
		}
		
		$params = array(
			'format' => 'json',
			'action' => 'wbcreateclaim',
			'snaktype' => 'value' ,
			'property' => 'P' . str_replace('P','',$claim['prop'].'') ,
			'value' => $value ,
			'token' => $token,
			'bot' => 1
		) ;


		global $tool_hashtag ;
		if ( isset($tool_hashtag) and $tool_hashtag != '' ) $summary = isset($summary) ? trim("$summary #$tool_hashtag") : "#$tool_hashtag" ;
		if ( isset($summary) and $summary != '' ) $params['summary'] = $summary ;
	
		if ( isset ( $claim['claim'] ) ) { // Set qualifier
			$params['action'] = 'wbsetqualifier' ;
			$params['claim'] = $claim['claim'] ;
		} else {
			$params['entity'] = $claim['q'] ;
		}

		$res = $this->doApiQuery( $params, $ch );
		
		if ( isset ( $_REQUEST['test'] ) ) {
			print "!!!!!<pre>" ; print_r ( $params ) ; print "</pre>" ;
			print "<pre>" ; print_r ( $claim ) ; print "</pre>" ;
			print "<pre>" ; print_r ( $res ) ; print "</pre>" ;
		}

		$this->last_res = $res ;
		if ( isset ( $res->error ) ) {
			$this->error = $res->error->info ;
			return false ;
		}

		
/*
		if ( $claim['type'] == 'string' ) {
			echo 'API edit result: <pre>' . htmlspecialchars( var_export( $res, 1 ) ) . '</pre>';
			echo '<hr>';
		}
*/		
		return true ;
	}

	function mergeItems ( $q_from , $q_to , $summary = '' ) {

		// Next fetch the edit token
		$ch = null;
		$res = $this->doApiQuery( array(
			'format' => 'json',
			'action' => 'query' ,
			'ignoreconflicts' => 'description' ,
			'meta' => 'tokens'
		), $ch );
		if ( !isset( $res->query->tokens->csrftoken ) ) {
			$this->error = 'Bad API response [setClaim]: <pre>' . htmlspecialchars( var_export( $res, 1 ) ) . '</pre>';
			return false ;
		}
		$token = $res->query->tokens->csrftoken;
	
		$opt = array(
			'format' => 'json',
			'action' => 'wbmergeitems',
			'fromid' => $q_from ,
			'toid' => $q_to ,
			'ignoreconflicts' => 'description|sitelink' ,
			'token' => $token,
			'bot' => 1
		) ;
			
		global $tool_hashtag ;
		if ( isset($tool_hashtag) and $tool_hashtag != '' ) $summary = isset($summary) ? trim("$summary #$tool_hashtag") : "#$tool_hashtag" ;
		if ( $summary != '' ) $opt['summary'] = $summary ;
		
	

		$res = $this->doApiQuery( $opt, $ch );

		if ( isset ( $_REQUEST['test'] ) ) {
			print "1<pre>" ; print_r ( $claim ) ; print "</pre>" ;
			print "2<pre>" ; print_r ( $res ) ; print "</pre>" ;
		}
		
		if ( isset ( $res->error ) ) {
			$this->error = $res->error->info ;
			return false ;
		}
		
/*
		if ( $claim['type'] == 'string' ) {
			echo 'API edit result: <pre>' . htmlspecialchars( var_export( $res, 1 ) ) . '</pre>';
			echo '<hr>';
		}
*/		
		return true ;
	}

	function deletePage ( $page , $reason ) {
		global $tool_hashtag ;
		if ( isset($tool_hashtag) and $tool_hashtag != '' ) $reason = isset($reason) ? trim("$reason #$tool_hashtag") : "#$tool_hashtag" ;

		// Next fetch the edit token
		$ch = null;
		$res = $this->doApiQuery( array(
			'format' => 'json',
			'action' => 'query' ,
			'meta' => 'tokens'
		), $ch );
		if ( !isset( $res->query->tokens->csrftoken ) ) {
			$this->error = 'Bad API response [setClaim]: <pre>' . htmlspecialchars( var_export( $res, 1 ) ) . '</pre>';
			return false ;
		}
		$token = $res->query->tokens->csrftoken;
		
		$p = array(
			'format' => 'json',
			'action' => 'delete',
			'title' => $page ,
			'token' => $token,
			'bot' => 1
		) ;
		if ( $reason != '' ) $p['reason'] = $reason ;
	
		$res = $this->doApiQuery( $p , $ch );
		
		if ( isset ( $_REQUEST['test'] ) ) {
			print "1<pre>" ; print_r ( $claim ) ; print "</pre>" ;
			print "2<pre>" ; print_r ( $res ) ; print "</pre>" ;
		}
		
		if ( isset ( $res->error ) ) {
			$this->error = $res->error->info ;
			return false ;
		}
		
/*
		if ( $claim['type'] == 'string' ) {
			echo 'API edit result: <pre>' . htmlspecialchars( var_export( $res, 1 ) ) . '</pre>';
			echo '<hr>';
		}
*/		
		return true ;
	}


	function doUploadFromFile ( $local_file , $new_file_name , $desc , $comment , $ignorewarnings ) {
		global $tool_hashtag ;
		if ( isset($tool_hashtag) and $tool_hashtag != '' ) $comment = isset($comment) ? trim("$desc #$tool_hashtag") : "#$tool_hashtag" ;
	
		$new_file_name = ucfirst ( str_replace ( ' ' , '_' , $new_file_name ) ) ;

		// Next fetch the edit token
		$ch = null;
		$res = $this->doApiQuery( array(
			'format' => 'json',
			'action' => 'query' ,
			'meta' => 'tokens'
		), $ch );
		if ( !isset( $res->query->tokens->csrftoken ) ) {
			$this->error = 'Bad API response [uploadFromURL]: <pre>' . htmlspecialchars( var_export( $res, 1 ) ) . '</pre>' ;
			return false ;
		}
		$token = $res->query->tokens->csrftoken;

		$params = array(
			'format' => 'json',
			'action' => 'upload' ,
			'comment' => $comment ,
			'text' => $desc ,
			'token' => $token ,
			'filename' => $new_file_name ,
			'file' => $local_file // '@' . 
		) ;
		
		if ( $ignorewarnings ) $params['ignorewarnings'] = 1 ;
		
		$res = $this->doApiQuery( $params , $ch , 'upload' );

		$this->last_res = $res ;
		if ( !isset($res->upload) ) {
			$this->error = $res->error->info ;
//		print_r ( $res ) ;
			return false ;
		} else if ( $res->upload->result != 'Success' ) {
			$this->error = $res->upload->result ;
			return false ;
		}

		return true ;
	}


	function doUploadFromURL ( $url , $new_file_name , $desc , $comment , $ignorewarnings ) {
		global $tool_hashtag ;
		if ( isset($tool_hashtag) and $tool_hashtag != '' ) $comment = isset($comment) ? trim("$desc #$tool_hashtag") : "#$tool_hashtag" ;
	
		if ( $new_file_name == '' ) {
			$a = explode ( '/' , $url ) ;
			$new_file_name = array_pop ( $a ) ;
		}
		$new_file_name = ucfirst ( str_replace ( ' ' , '_' , $new_file_name ) ) ;

		// Download file
		$basedir = '/data/project/magnustools/tmp' ;
		$tmpfile = tempnam ( $basedir , 'doUploadFromURL' ) ;
		copy($url, $tmpfile) ;

		// Next fetch the edit token
		$ch = null;
		$res = $this->doApiQuery( array(
			'format' => 'json',
			'action' => 'query' ,
			'meta' => 'tokens'
		), $ch );
		if ( !isset( $res->query->tokens->csrftoken ) ) {
			$this->error = 'Bad API response [uploadFromURL]: <pre>' . htmlspecialchars( var_export( $res, 1 ) ) . '</pre>' ;
			unlink ( $tmpfile ) ;
			return false ;
		}
		$token = $res->query->tokens->csrftoken;

		$params = array(
			'format' => 'json',
			'action' => 'upload' ,
			'comment' => $comment ,
			'text' => $desc ,
			'token' => $token ,
			'filename' => $new_file_name ,
			'file' => $tmpfile // '@' . 
		) ;
		
		if ( $ignorewarnings ) $params['ignorewarnings'] = 1 ;
		
		$res = $this->doApiQuery( $params , $ch , 'upload' );

		unlink ( $tmpfile ) ;
		
		$this->last_res = $res ;
		if ( $res->upload->result != 'Success' ) {
			$this->error = $res->upload->result ;
			return false ;
		}

		return true ;
	}



	
	function isAuthOK () {

		$ch = null;

		// First fetch the username
		$res = $this->doApiQuery( array(
			'format' => 'json',
			'action' => 'query',
			'uiprop' => 'groups|rights' ,
			'meta' => 'userinfo',
		), $ch , 'userinfo' );

		if ( isset( $res->error->code ) && $res->error->code === 'mwoauth-invalid-authorization' ) {
			// We're not authorized!
			$this->error = 'You haven\'t authorized this application yet! Go <a target="_blank" href="' . htmlspecialchars( $_SERVER['SCRIPT_NAME'] ) . '?action=authorize">here</a> to do that, then reload this page.' ;
			return false ;
		}

		if ( !isset( $res->query->userinfo ) ) {
/*			if ( isset($_REQUEST['test']) ) {
				$info = curl_getinfo($ch);
				print "<pre>" ;
				print_r ( $info ) ;
				print "</pre>" ;
			}*/
			$this->error = 'Not authorized (bad API response [isAuthOK]: ' . htmlspecialchars( json_encode( $res) ) . ')' ;
			return false ;
		}
		if ( isset( $res->query->userinfo->anon ) ) {
			$this->error = 'Not logged in. (How did that happen?)' ;
			return false ;
		}

		$this->userinfo = $res->query->userinfo ;
		

		return true ;
	}



}


///////////////


/*
// Take any requested action
switch ( isset( $_GET['action'] ) ? $_GET['action'] : '' ) {
	case 'download':
		header( 'Content-Type: text/plain' );
		readfile( __FILE__ );
		return;

	case 'authorize':
		doAuthorizationRedirect();
		return;

	case 'edit':
		doEdit();
		break;
}
*/

// ******************** CODE ********************






/*
<!DOCTYPE html>
<html lang="en" dir="ltr">
 <head>
  <meta charset="UTF-8" />
  <title>OAuth Hello World!</title>
 </head>
 <body>
<p>This is a very simple "<a href="//en.wikipedia.org/wiki/Hello_world_program">Hello world</a>" program to show how to use OAuth. If you so desire, you may <a href="<?php echo htmlspecialchars( $_SERVER['SCRIPT_NAME'] );?>?action=download">download this file</a>. For a more end-user friendly version, look at <a href="enduser.php">enduser.php</a>.</p>

<h2>Overview</h2>
<p>OAuth is a method for your application to act on behalf of a user on a website, without having to know the user's username and password. First your application is regisetered with the website, then you send the user to a special page on the website where they give your application permission, and then you provide special HTTP headers when accessing the website.</p>

<h2>Creating your consumer</h2>
<p>To be able to use OAuth in your application, you first need to register it as a consumer. To do this, you visit Special:OAuthConsumerRegistration on the OAuth central wiki. For WMF wikis, this is currently <a href="https://www.mediawiki.org/wiki/Special:OAuthConsumerRegistration/propose">mediawiki.org</a>, but will likely change to Meta once OAuth is fully deployed.</p>
<p>On this page, you will fill out information required by your application. Most of the fields are straightforward. Of the rest:</p>
<ul>
 <li>OAuth "callback" URL: After the user authorizes the application, their browser will be sent to this URL. It will be given two parameters, <code>oauth_verifier</code> and <code>oauth_token</code>, which your application will need in order to complete the authorization process.</li>
 <li>Applicable wiki: If your app is only for use in one wiki, specify the wiki id here (this may be retrieved from the API with <code>action=query&amp;meta=siteinfo</code>). If your app is for use on all wikis, specify "*" (without the quotes).</li>
 <li>Applicable grants: Check the checkbox for the grants that provide the rights your application needs. Note that "Basic rights" is almost certainly required, and that even if your application specifies advanced rights such as "Delete pages" your application will still not be able to delete pages on behalf of users who don't already have the delete right.</li>
 <li>Usage restrictions (JSON): This can be used to limit usage of your application, e.g. to certain IP addresses. The default value should be fine.</li>
 <li>Public RSA key: OAuth requires that requests be signed; this can be done by using a shared secret, or by using <a href="https://en.wikipedia.org/wiki/Public-key_cryptography">public-key cryptography</a>. If you want to use the latter, provide a public key here.</li>
</ul>
<p>After submitting your registration request, you will be returned a "consumer token" and a "secret token". In this Hello world program, these go in your ini file as consumerKey and consumerSecret. Note you can later update the Usage restrictions and Public RSA key, and can reset the secret token.</p>
<p>Your application must then be approved by someone with the "mwoauthmanageconsumer" user right.</p>

<h2>Authorizing a user</h2>
<p>When a new user wishes to use your application, they must first authorize it. You do this by making a call to Special:OAuth/initiate to get a request token, then send the user to Special:OAuth/authorize. If the user authorizes your app, the user will be redirected back to your callback URL with the <code>oauth_verifier</code> parameter set; you then call Special:OAuth/token to fetch the access token.</p>

<h2>Deauthorizing a user</h2>
<p>A user may revoke the authorization for the application by visiting Special:OAuthManageMyGrants on the OAuth central wiki.</p>

<h2>Try it out!</h2>
<ul>
 <li><a href="<?php echo htmlspecialchars( $_SERVER['SCRIPT_NAME'] );?>?action=authorize">Authorize this application</a></li>
 <li><a href="<?php echo htmlspecialchars( $_SERVER['SCRIPT_NAME'] );?>?action=edit">Post to your talk page</a></li>
 <li><a href="<?php echo htmlspecialchars( $mytalkUrl );?>">Visit your talk page</a></li>
</ul>

</body>
</html>

*/
