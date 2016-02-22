<?php
/**
 * Class Authenticator
 *
 * @link         https://github.com/google/google-authenticator
 *
 * @filesource   Authenticator.php
 * @created      24.11.2015
 * @package      chillerlan\GoogleAuth
 * @author       Smiley <smiley@chillerlan.net>
 * @copyright    2015 Smiley
 * @license      MIT
 */

namespace chillerlan\GoogleAuth;

use chillerlan\Base32\Base32;

/**
 * Yet another Google authenticator implemetation!
 *
 * @link http://jacob.jkrall.net/totp/
 * @link https://github.com/PHPGangsta/GoogleAuthenticator
 * @link https://github.com/devicenull/PHP-Google-Authenticator
 */
class Authenticator{

	/**
	 * @link https://github.com/google/google-authenticator/wiki/Key-Uri-Format#digits
	 *
	 * @var int
	 */
	public static $digits = 6;

	/**
	 * @link https://github.com/google/google-authenticator/wiki/Key-Uri-Format#period
	 *
	 * @var int
	 */
	public static $period = 30;

	/**
	 * Sets the code length to either 6 or 8
	 *
	 * @param int $digits
	 *
	 * @throws \chillerlan\GoogleAuth\AuthenticatorException
	 */
	public static function setDigits($digits = 6){

		if(!in_array(intval($digits), [6, 8], true)){
			throw new AuthenticatorException('Invalid code length: '.$digits);
		}

		self::$digits = $digits;
	}

	/**
	 * Sets the period to a value between 10 and 60 seconds
	 *
	 * @param int $period
	 *
	 * @throws \chillerlan\GoogleAuth\AuthenticatorException
	 */
	public static function setPeriod($period = 30){
		$period = intval($period);

		if($period < 15 || $period > 60){ // for cereal?
			throw new AuthenticatorException('Invalid period: '.$period);
		}

		self::$period = $period;
	}

	/**
	 * Generates a new (secure random) secret phrase
	 * "an arbitrary key value encoded in Base32 according to RFC 3548"
	 *
	 * @link https://github.com/PHPGangsta/GoogleAuthenticator/pull/10
	 *
	 * @param int $secretLength
	 *
	 * @return string
	 * @throws \chillerlan\GoogleAuth\AuthenticatorException
	 */
	public static function createSecret($secretLength = 16){
		$secretLength = intval($secretLength);

		// ~ 80 to 640 bits
		if($secretLength < 16 || $secretLength > 128){
			throw new AuthenticatorException('Invalid secret length: '.$secretLength);
		}

		// https://github.com/paragonie/random_compat/blob/7cf3fdb7797f40d4480d0f2e6e128f4c8b25600b/ERRATA.md
		$random = function_exists('random_bytes')
			? random_bytes($secretLength) // PHP 7
			: mcrypt_create_iv($secretLength, MCRYPT_DEV_URANDOM); // PHP 5.6+

		$chars = str_split(Base32::RFC3548);
		$secret = '';

		for($i = 0; $i < $secretLength; $i++){
			$secret .= $chars[ord($random[$i])&31];
		}

		return $secret;
	}

	/**
	 * Calculate the code with the given secret and point in time
	 *
	 * @param string $secret
	 * @param float  $timeslice -> floor($timestamp / 30)
	 *
	 * @return string
	 * @throws \chillerlan\GoogleAuth\AuthenticatorException
	 */
	public static function getCode($secret, $timeslice = null){
		self::checkSecret($secret);

		// Pack time into binary string
		$time = str_repeat(chr(0), 4).pack('N*', self::checkTimeslice($timeslice));
		// Hash it with users secret key
		$hmac = hash_hmac('SHA1', $time, Base32::toString($secret), true);
		// Use last nibble of result as index/offset
		$offset = ord(substr($hmac, -1))&0x0F;
		// Unpack binary value, only 32 bits
		$value = unpack('N', substr($hmac, $offset, 4))[1]&0x7FFFFFFF;

		return str_pad($value % pow(10, self::$digits), self::$digits, '0', STR_PAD_LEFT);
	}

	/**
	 * Checks the given code against the secret with a given point in time and accepting adjacent codes
	 *
	 * @param string $code
	 * @param string $secret
	 * @param float  $timeslice -> floor($timestamp / 30)
	 * @param int    $adjacent
	 *
	 * @return bool
	 * @throws \chillerlan\GoogleAuth\AuthenticatorException
	 */
	public static function verifyCode($code, $secret, $timeslice = null, $adjacent = 1){
		self::checkSecret($secret);
		$timeslice = self::checkTimeslice($timeslice);

		for($i = -$adjacent; $i <= $adjacent; $i++){
			/**
			 * A timing safe equals comparison
			 * more info here: http://blog.ircmaxell.com/2014/11/its-all-about-time.html
			 */
			if(hash_equals(self::getCode($secret, $timeslice + $i), $code)){
				return true;
			}
		}

		return false;
	}

	/**
	 * Creates an URI for use in QR codes for example
	 *
	 * @param string $secret
	 * @param string $label
	 * @param string $issuer
	 *
	 * @return string
	 * @throws \chillerlan\GoogleAuth\AuthenticatorException
	 */
	public static function getUri($secret, $label, $issuer){
		self::checkSecret($secret);

		// https://github.com/google/google-authenticator/wiki/Key-Uri-Format#parameters
		$values = [
			'secret' => $secret,
			'issuer' => $issuer,
		];

		if(self::$digits !== 6){
			$values['digits'] = self::$digits;
		}

		if(self::$period !== 30){
			$values['period'] = self::$period;
		}

		return 'otpauth://totp/'.$label.'?'.http_build_query($values);
	}

	/**
	 * Generates an URL to the Google (deprecated) charts QR code API.
	 *
	 * @link       https://github.com/codemasher/php-qrcode/
	 * @deprecated https://developers.google.com/chart/infographics/docs/qr_codes
	 *
	 * @param string $secret
	 * @param string $label
	 * @param string $issuer
	 *
	 * @return string
	 */
	public static function getGoogleQr($secret, $label, $issuer) {

		$query = [
			'chs'  => '200x200',
			'chld' => 'M|0',
			'cht'  => 'qr',
			'chl'  => self::getUri($secret, $label, $issuer),
		];

		return 'https://chart.googleapis.com/chart?'.http_build_query($query);
	}

	/**
	 * Checks if the secret phrase matches the character set
	 * @param mixed $secret
	 *
	 * @throws \chillerlan\GoogleAuth\AuthenticatorException
	 */
	protected static function checkSecret($secret){

		if(!(bool)preg_match('/^['.Base32::RFC3548.']+$/', $secret)){
			throw new AuthenticatorException('Invalid secret phrase!');
		}

	}

	/**
	 * @param mixed $timeslice
	 *
	 * @return float
	 */
	protected static function checkTimeslice($timeslice){

		if($timeslice === null || !is_float($timeslice)){
			$timeslice = floor(time() / self::$period);
		}

		return $timeslice;
	}

}
