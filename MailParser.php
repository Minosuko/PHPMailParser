<?php
// smaller and optimized version
// https://github.com/plancake/official-library-php-email-parser
class EmailParser {
	const PLAINTEXT = 1;
	const HTML = 2;
	private $isImapExtensionAvailable = false;
	private $emailRawContent;
	protected $rawFields;
	protected $rawBodyLines;
	public function __construct(){
		if (function_exists('imap_open'))
			$this->isImapExtensionAvailable = true;
	}
	public function parse($emailRawContent){
		$this->emailRawContent = $emailRawContent;
		$this->extractHeadersAndRawBody();
	}
	private function extractHeadersAndRawBody(){
		$lines = preg_split("/(\r?\n|\r)/", $this->emailRawContent);
		$currentHeader = '';
		$i = 0;
		foreach($lines as $line){
			if(self::isNewLine($line)){
				$this->rawBodyLines = array_slice($lines, $i);
				break;
			}
			if($this->isLineStartingWithPrintableChar($line)){
				preg_match('/([^:]+): ?(.*)$/', $line, $matches);
				$newHeader = strtolower($matches[1]);
				$value = $matches[2];
				$this->rawFields[$newHeader] = $value;
				$currentHeader = $newHeader;
			}else
				if($currentHeader) $this->rawFields[$currentHeader] .= substr($line, 1);
			$i++;
		}
	}
	public function getSubject(){
		$ret = '';
		if(!isset($this->rawFields['subject']))
			throw new Exception("Couldn't find the subject of the email");
		if($this->isImapExtensionAvailable)
			foreach(imap_mime_header_decode($this->rawFields['subject']) as $h){
				$charset = ($h->charset == 'default') ? 'US-ASCII' : $h->charset;
				$ret .= iconv($charset, "UTF-8//TRANSLIT", $h->text);
			}
		else
			$ret = utf8_encode(iconv_mime_decode($this->rawFields['subject']));
		return $ret;
	}
	public function getCc(){
		if (!isset($this->rawFields['cc'])) return array();
		return explode(',', $this->rawFields['cc']);
	}
	public function getTo(){
		if((!isset($this->rawFields['to'])) || (!count($this->rawFields['to']))) throw new Exception("Couldn't find the recipients of the email");
		return explode(',', $this->rawFields['to']);
	}
	public function getBody($returnType=self::PLAINTEXT)
	{
		$body = '';
		$detectedContentType = false;
		$contentTransferEncoding = null;
		$charset = 'ASCII';
		$waitingForContentStart = true;
		if($returnType == self::HTML)
			$contentTypeRegex = '/^Content-Type: ?text\/html/i';
		else
			$contentTypeRegex = '/^Content-Type: ?text\/plain/i';
		preg_match_all('!boundary=(.*)$!mi', $this->emailRawContent, $matches);
		$boundaries = $matches[1];
		foreach($boundaries as $i => $v) $boundaries[$i] = str_replace(array("'", '"'), '', $v);
		foreach($this->rawBodyLines as $line){
			if(!$detectedContentType){
				if(preg_match($contentTypeRegex, $line, $matches)) $detectedContentType = true;
				if(preg_match('/charset=(.*)/i', $line, $matches)) $charset = strtoupper(trim($matches[1], '"'));
			}elseif($detectedContentType && $waitingForContentStart){
				if(preg_match('/charset=(.*)/i', $line, $matches)) $charset = strtoupper(trim($matches[1], '"')); 
				if($contentTransferEncoding == null && preg_match('/^Content-Transfer-Encoding: ?(.*)/i', $line, $matches)) $contentTransferEncoding = $matches[1];
				if(self::isNewLine($line)) $waitingForContentStart = false;
			}else{
				if(is_array($boundaries)) if(in_array(substr($line, 2), $boundaries)) break;
				$body .= $line . "\n";
			}
		}
		if(!$detectedContentType)
			$body = implode("\n", $this->rawBodyLines);
		$body = preg_replace('/((\r?\n)*)$/', '', $body);
		if($contentTransferEncoding == 'base64')
			$body = base64_decode($body);
		elseif($contentTransferEncoding == 'quoted-printable')
			$body = quoted_printable_decode($body);
		if($charset != 'UTF-8'){
			$charset = str_replace("FORMAT=FLOWED", "", $charset);
			$bodyCopy = $body; 
			$body = iconv($charset, 'UTF-8//TRANSLIT', $body);
			if($body === FALSE) $body = utf8_encode($bodyCopy);
		}
		return $body;
	}
	public function getPlainBody(){
		return $this->getBody(self::PLAINTEXT);
	}
	public function getHTMLBody(){
		return $this->getBody(self::HTML);
	}
	public function getHeader($headerName){
		$headerName = strtolower($headerName);
		if(isset($this->rawFields[$headerName])) return $this->rawFields[$headerName];
		return '';
	}
	public static function isNewLine($line){
		$line = str_replace(["\r","\n"], '', $line);
		return (strlen($line) === 0);
	}
	private function isLineStartingWithPrintableChar($line){
		return preg_match('/^[A-Za-z]/', $line);
	}
}
?>