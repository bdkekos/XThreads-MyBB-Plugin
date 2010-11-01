<?php
if(!defined('IN_MYBB'))
	die('This file cannot be accessed directly.');

/**
 * Abstract class/interface to handle URL fetching
 */
class XTUrlFetcher {
	/**
	 * URL to fetch
	 */
	var $url;
	/**
	 * Timeout
	 */
	var $timeout = 10;
	
	var $follow_redir = 5;
	var $referer=null;
	var $user_agent=null;
	
	/**
	 * Callback function to send meta information to
	 * Should accept 3 arguments: this object, meta name and value
	 * Should return true if everything is well, false to abort
	 */
	var $meta_function=null;
	/**
	 * Callback function to process chunks of body data; if not set, will return all data on fetch() call
	 * Should accept two arguments: this object and data
	 *  -> Note that although the variables are passed as references (for speed purposes), they should NOT be modified
	 * Should return true if everything is well, false to abort
	 */
	var $body_function=null;
	
	/**
	 * Error number and string
	 * @access protected
	 */
	var $_errno=null;
	var $_errstr=null;
	
	/**
	 * Whether or not connection was aborted by calling app
	 * Should not be written to externally
	 */
	var $aborted=false;
	
	/**
	 * Whether this fetcher can be used
	 * @return boolean true if fetcher can be used
	 */
	//abstract static function available();
	
	/**
	 * Free allocated resources
	 */
	function close() {}
	function __destruct() {
		$this->close();
	}
	
	/**
	 * Fetch $url
	 * @return true if successful, false if not, and null if aborted
	 */
	//abstract function fetch();
	
	/**
	 * Set $referer based on $url; uses the host of $url as the referer
	 */
	function setRefererFromUrl() {
		$purl = parse_url($this->url);
		$this->referer = $purl['scheme'].'://'.$purl['host'].'/';
	}
	
	/**
	 * Generate an array of headers; does not include Host or GET header
	 * @access protected
	 */
	function &_generateHeaders() {
		$headers = array(
			'Connection: close',
			'Accept: */*',
		);
		// TODO: follow_redir, encoding
		
		if(isset($this->user_agent))
			$headers[] = 'User-Agent: '.$this->user_agent;
		if(isset($this->charset))
			$headers[] = 'Accept-Charset: '.$this->charset.';q=0.5, *;q=0.2';
		if(isset($this->lang))
			$headers[] = 'Accept-Language: '.$this->lang.';q=0.5, *;q=0.3';
		if(isset($this->referer))
			$headers[] = 'Referrer: '.$this->referer;
		
		return $headers;
	}
	
	/**
	 * Processes a HTTP header, and calls the meta function if necessary
	 * @access protected
	 * @return false to abort, true otherwise
	 */
	function _processHttpHeader($header) {
		if(!isset($this->meta_function)) return true;
		
		$result = self::_processHttpHeader_parse($header);
		if(empty($result)) return true;
		foreach($result as $mname => &$mdata) {
			if(!call_user_func_array($this->meta_function, array(&$this, &$mname, &$mdata))) {
				$this->aborted = true;
				return false;
			}
		}
		
		return true;
	}
	/**
	 * Parse info from HTTP header
	 * @access private
	 * @return array of info retrieved, or null if nothing retrieved
	 */
	static function _processHttpHeader_parse(&$header) {
		$header = trim($header);
		$p = strpos($header, ':');
		if(!$p) {
			// look for HTTP/1.1 type header
			if(strtoupper(substr($header, 0, 5)) == 'HTTP/') {
				if(preg_match('~^HTTP/[0-9.]+ (\d+) (.*)$~i', $header, $match)) {
					return array('retcode' => array(intval($match[1]), trim($match[2])));
				}
			}
			return null;
		}
		$hdata = trim(substr($header, $p+1));
		switch(strtolower(substr($header, 0, $p))) {
			case 'content-length':
				$size = intval($hdata);
				if($size || $hdata === '0') {
					return array('size' => $size);
				}
			break;
			case 'content-disposition':
				foreach(explode(';', $hdata) as $disp) {
					$disp = trim($disp);
					if(strtolower(substr($disp, 0, 9)) == 'filename=') {
						$tmp = substr($disp, 9);
						if(!xthreads_empty($tmp)) {
							if($tmp{0} == '"' && $tmp{strlen($tmp)-1} == '"')
								$tmp = substr($tmp, 1, -1);
							return array('name' => trim(str_replace("\x0", '', $tmp)));
						}
					}
				}
			break;
			case 'content-type':
				return array('type' => $hdata);
			break;
		}
		return null;
	}
	
	// since fread'ing won't necessarily fill the requested buffer size...
	static function &fill_fread(&$fp, $len) {
		$fill = 0;
		$ret = '';
		while(!feof($fp) && $len > 0) {
			$data = fread($fp, $len);
			$len -= strlen($data);
			$ret .= $data;
		}
		return $ret;
	}
	
	function getError(&$code=null) {
		$code = $this->errno;
		return $this->errstr;
	}
}

/**
 * Fetch URL through cURL
 */
class XTUrlFetcher_Curl extends XTUrlFetcher {
	/**
	 * Internal cURL resource handle
	 * @access private
	 */
	var $_ch;
	
	var $name = 'cURL';
	
	static function available() {
		return function_exists('curl_init');
	}
	
	function XTUrlFetcher_Curl() {
		$this->_ch = curl_init();
	}
	function close() {
		if(isset($this->_ch))
			@curl_close($this->_ch); // curl_close may not succeed if called within callback
	}
	
	function fetch() {
		curl_setopt($this->_ch, CURLOPT_URL, $this->url);
		curl_setopt($this->_ch, CURLOPT_HEADER, false);
		curl_setopt($this->_ch, CURLOPT_TIMEOUT, $this->timeout);
		if(isset($this->user_agent))
			curl_setopt($this->_ch, CURLOPT_USERAGENT, $this->user_agent);
		if(isset($this->referer))
			curl_setopt($this->_ch, CURLOPT_REFERER, $this->referrer);
		
		if($this->follow_redir) {
			curl_setopt($this->_ch, CURLOPT_AUTOREFERER, true);
			// PHP safe mode may restrict the following
			@curl_setopt($this->_ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($this->_ch, CURLOPT_MAXREDIRS, $this->follow_redir);
		}
		// TODO: 
		curl_setopt($this->_ch, CURLOPT_ENCODING, '');
		
		if(isset($this->meta_function)) {
			// can only use this if http/s request
			if(strtolower(substr($this->url, 0, 4)) == 'http')
				curl_setopt($this->_ch, CURLOPT_HEADERFUNCTION, array($this, 'curl_header_func'));
		}
		if(isset($this->body_function)) {
			curl_setopt($this->_ch, CURLOPT_RETURNTRANSFER, false);
			curl_setopt($this->_ch, CURLOPT_WRITEFUNCTION, array($this, 'curl_body_func'));
		} else
			curl_setopt($this->_ch, CURLOPT_RETURNTRANSFER, true);
		
		$success = curl_exec($this->_ch);
		if($this->aborted)
			return null;
		else
			return $success;
	}
	
	function getError() {
		$this->errno = curl_errno($this->_ch);
		$this->errstr = curl_error($this->_ch);
		return parent::getError();
	}
	
	function curl_header_func(&$ch, $header) {
		if($this->_processHttpHeader(trim($header)))
			return strlen($header);
		else {
			$this->close();
			return 0;
		}
	}
	function curl_body_func(&$ch, $data) {
		if(call_user_func_array($this->body_function, array(&$this, &$data)))
			return strlen($data);
		else {
			$this->aborted = true;
			$this->close();
			return 0;
		}
	}
}


/**
 * Fetch URL through Sockets
 */
class XTUrlFetcher_Socket extends XTUrlFetcher {
	/**
	 * Optional preferred character set to send via Accept header
	 */
	var $charset;
	/**
	 * Optional preferred language set to send via Accept header
	 */
	var $lang;
	
	var $name = 'Sockets';
	
	static function available() {
		return function_exists('fsockopen');
	}
	
	function fetch() {
		$purl = @parse_url($this->url);
		if(!isset($purl['path']) || $purl['path'] === '')
			$purl['path'] = '/';
		if(@$purl['query'])
			$purl['path'] .= '?'.@$purl['query'];
		if(!($fr = @fsockopen($purl['host'], $purl['port'], $this->errno, $this->errstr, $this->timeout))) {
			//$this->errno = 0;
			//$this->errstr = 'Can\'t write to socket.';
			return false;
		}
		@stream_set_timeout($fr, $this->timeout);
		$headers = array_merge(array(
			'GET '.$purl['path'].' HTTP/1.1',
			'Host: '.$purl['host'],
		), $this->_generateHeaders());
		
		$headers[] = "\r\n";
		
		if(!@fwrite($fr, implode("\r\n", $headers))) {
			$this->errno = 0;
			$this->errstr = 'cantwritesocket';
			fclose($fr);
			return false;
		}
		
		$databuf = ''; // returned string if no body_function defined
		$doneheaders = false;
		while(!feof($fr)) {
			if(!$doneheaders) {
				$data = self::fill_fread($fr, 16384);
				$len = strlen($data);
				$p = strpos($data, "\r\n\r\n");
				if(!$p || $p > 12288) { // should be no reason to have >12KB headers
					$this->errno = 0;
					$this->errstr = 'headernotfound';
					break;
				}
				// parse headers
				if(isset($this->meta_function)) {
					foreach(explode("\r\n", substr($data, 0, $p)) as $header) {
						if(!$this->_processHttpHeader(trim($header))) {
							break;
						}
					}
					if($this->aborted) break;
				}
				
				$p += 4;
				$data = substr($data, $p);
				$len -= $p;
				$doneheaders = true;
			} else {
				$len = 0;
				while(!feof($fr) && !$len) {
					$data = fread($fr, 16384);
					$len = strlen($data);
				}
			}
			if($len) {
				if(isset($this->body_function)) {
					if(!call_user_func_array($this->body_function, array(&$this, &$data))) {
						$this->aborted = true;
						break;
					}
				} else {
					$databuf .= $data;
				}
			}
		}
		fclose($fr);
		if($this->aborted) return null;
		if(isset($this->errstr)) return false;
		
		if(isset($this->body_function)) return true;
		return $databuf;
	}
	
}

/**
 * Fetch URL through PHP fopen
 */
class XTUrlFetcher_Fopen extends XTUrlFetcher {
	var $name = 'fopen';
	
	static function available() {
		//return @ini_get('allow_url_fopen');
		return true;
		// data:// streams don't require allow_url_fopen
	}
	
	function fetch() {
		$httpopts = array(
			'header' => $this->_generateHeaders(),
			'max_redirects' => $this->follow_redir
		);
		$context = stream_context_create(array(
			'http' => $httpopts,
			'https' => $httpopts
		));
		//if(isset($this->user_agent))
		//	@ini_set('user_agent', $this->user_agent);
		if(!($fr = @fopen($this->url, 'rb', false, $context))) {
			$this->errno = 0;
			$this->errstr = 'urlopenfailed';
			return false;
		}
		@stream_set_timeout($fr, $this->timeout);
		
		// send headers if possible
		$meta = @stream_get_meta_data($fr);
		if(isset($meta['wrapper_data'])) {
			foreach($meta['wrapper_data'] as $header) {
				if(!$this->_processHttpHeader($header)) {
					fclose($fr);
					return null;
				}
			}
		}
		
		
		$databuf = ''; // returned string if no body_function defined
		while(!feof($fr)) {
			$len = 0;
			while(!feof($fr) && !$len) {
				$data = fread($fr, 16384);
				$len = strlen($data);
			}
			
			if($len) {
				if(isset($this->body_function)) {
					if(!call_user_func_array($this->body_function, array(&$this, &$data))) {
						$this->aborted = true;
						break;
					}
				} else {
					$databuf .= $data;
				}
			}
		}
		fclose($fr);
		if($this->aborted) return null;
		//if(isset($this->errstr)) return false;
		
		if(isset($this->body_function)) return true;
		return $databuf;
	}
}

/**
 * URL fetcher factory method
 * @return a new XTUrlFetcher object, depending on what is available
 */
function getXTUrlFetcher($scheme='') {
	$scheme = strtolower($scheme);
	if(!$scheme || $scheme != 'data') {
		if(XTUrlFetcher_Curl::available())
			return new XTUrlFetcher_Curl;
		if((!$scheme || substr($scheme, 0, 4) == 'http') && XTUrlFetcher_Socket::available())
			return new XTUrlFetcher_Socket;
	}
	if(XTUrlFetcher_Fopen::available())
		return new XTUrlFetcher_Fopen;
	
	return null; // nothing can fetch it for us... >_>
}


// TODO add: support for data:// stream, sockets don't try FTP, improve handling of fopen