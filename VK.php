<?php

namespace VK;
use VK\VKException;

/**
 * The PHP class for vk.com API and to support OAuth.
 * @author Vlad Pronsky <vladkens@yandex.ru>
 * @license https://raw.github.com/vladkens/VK/master/LICENSE MIT
 */

class VK
{
    /**
     * VK application id.
     * @var string
     */
    private $app_id;

    /**
     * VK application secret key.
     * @var string
     */
    private $api_secret;

    /**
     * API version. If null uses latest version.
     * @var int
     */
    private $api_version = '5.52';

    /**
     * VK access token.
     * @var string
     */
    private $access_token;

    /**
     * Authorization status.
     * @var bool
     */
    private $auth = false;

    /**
     * Instance curl.
     * @var Resource
     */
    private $ch;

    const AUTHORIZE_URL = 'https://oauth.vk.com/authorize';
    const ACCESS_TOKEN_URL = 'https://oauth.vk.com/access_token';

    /**
     * Constructor.
     * @param   string $app_id
     * @param   string $api_secret
     * @param   string $access_token
     * @throws  VKException
     */
    public function __construct($app_id, $api_secret, $access_token = null)
    {
        $this->app_id = $app_id;
        $this->api_secret = $api_secret;
        $this->setAccessToken($access_token);

        $this->ch = curl_init();
    }

    /**
     * Destructor.
     */
    public function __destruct()
    {
        curl_close($this->ch);
    }

    /**
     * Set special API version.
     * @param   int $version
     * @return  void
     */
    public function setApiVersion($version)
    {
        $this->api_version = $version;
    }

    /**
     * Set Access Token.
     * @param   string $access_token
     * @throws  VKException
     * @return  void
     */
    public function setAccessToken($access_token)
    {
        $this->access_token = $access_token;
    }

    /**
     * Returns base API url.
     * @param   string $method
     * @param   string $response_format
     * @return  string
     */
    public function getApiUrl($method, $response_format = 'json')
    {
        return 'https://api.vk.com/method/' . $method . '.' . $response_format;
    }

    /**
     * Returns authorization link with passed parameters.
     * @param   string $api_settings
     * @param   string $callback_url
     * @param   bool $test_mode
     * @return  string
     */
    public function getAuthorizeUrl($api_settings = '',
                                    $callback_url = 'https://api.vk.com/blank.html', $response_type = 'code', $test_mode = false)
    {
        $parameters = array(
            'client_id' => $this->app_id,
            'scope' => $api_settings,
            'redirect_uri' => $callback_url,
            'response_type' => $response_type,
        );

        if ($test_mode)
            $parameters['test_mode'] = 1;

        return $this->createUrl(self::AUTHORIZE_URL, $parameters);
    }

    /**
     * Returns access token by code received on authorization link.
     * @param   string $code
     * @param   string $callback_url
     * @throws  VKException
     * @return  array
     */
    public function getAccessToken($code, $callback_url = 'https://api.vk.com/blank.html')
    {
        if (!is_null($this->access_token) && $this->auth) {
            throw new VKException('Already authorized.');
        }

        $parameters = array(
            'client_id' => $this->app_id,
            'client_secret' => $this->api_secret,
            'code' => $code,
            'redirect_uri' => $callback_url
        );

        $rs = json_decode($this->request(
            $this->createUrl(self::ACCESS_TOKEN_URL, $parameters)), true);

        if (isset($rs['error'])) {
            throw new VKException($rs['error'] .
                (!isset($rs['error_description']) ?: ': ' . $rs['error_description']));
        } else {
            $this->auth = true;
            $this->access_token = $rs['access_token'];
            return $rs;
        }
    }

    /**
     * Return user authorization status.
     * @return  bool
     */
    public function isAuth()
    {
        return !is_null($this->access_token);
    }
		
		public function getProfileInfo(){
     $access_token = $this->access_token;
			if(!$access_token){
				return false;
			}
			$fields = 'photo_50';
  		$rs = $this->api('users.get', compact('access_token', 'fields'));
			if(isset($rs['error'])){
				throw new VKException($rs['error']['error_msg']);
			}
			return $rs['response'][0];
		}

    /**
     * Check for validity access token.
     * @param   string $access_token
     * @return  bool
     */
    public function checkAccessToken($access_token = null)
    {
        $token = is_null($access_token) ? $this->access_token : $access_token;
        if (is_null($token)) return false;

        $rs = $this->api('getUserSettings', array('access_token' => $token));
        return $rs['response'];
    }

		public function bulkApi($method, $params, &$result, $total = null){
			
			if($total === null){
				$rs = $this->api($method, $params);
				if(isset($rs["error"])){
					throw new VKException($rs['error']['error_msg']);
				}
				$total = $rs['response']['count'];
			}
			$collected = count($result);
			$json_params = json_encode($params);
			$code = "
		    var total = $total,
				    collected = $collected,
						limit = 25,
						tmp,
						params = $json_params,
						result = [];
			  while(limit > 0 && collected < total){
					limit = limit - 1;
					params.offset = collected;
					params.is_closed = 0;
					tmp = API.$method(params);
					collected = collected + tmp.items.length;
					result = result + tmp.items;
				}
				return result;
			";
			$rs = $this->api('execute', compact('code'));
			if(isset($rs["error"])){
				throw new VKException($rs['error']['error_msg']);
			}
		  $result = array_merge($result, $rs['response']);
			// static $ii = 0;
			// if($ii>6){
				// return $result;
			// }
			// $ii++;
			// x([count($result), $total]);
			// flush();
			// ob_flush();
			if(count($result)<$total){
				usleep(250000);
				$this->bulkApi($method, $params, $result, $total);
			}
			// j($result);
			
		}
		
    /**
     * Execute API method with parameters and return result.
     * @param   string $method
     * @param   array $parameters
     * @param   string $format
     * @param   string $requestMethod
     * @return  mixed
     */
    public function api($method, $parameters = array(), $format = 'array', $requestMethod = 'get')
    {
        // $parameters['timestamp'] = time();
        // $parameters['api_id'] = $this->app_id;
        // $parameters['random'] = rand(0, 10000);

        if (!array_key_exists('access_token', $parameters) && !is_null($this->access_token)) {
            $parameters['access_token'] = $this->access_token;
        }

        if (!array_key_exists('v', $parameters) && !is_null($this->api_version)) {
            $parameters['v'] = $this->api_version;
        }

        ksort($parameters);

        $sig = '';
        foreach ($parameters as $key => $value) {
            $sig .= $key . '=' . $value;
        }
        $sig .= $this->api_secret;

        $parameters['sig'] = md5($sig);

        if ($method == 'execute' || $requestMethod == 'post') {
            $rs = $this->request(
                $this->getApiUrl($method, $format == 'array' ? 'json' : $format), "POST", $parameters);
        } else {
            $rs = $this->request($this->createUrl(
                $this->getApiUrl($method, $format == 'array' ? 'json' : $format), $parameters));
        }
        return $format == 'array' ? json_decode($rs, true) : $rs;
    }

    /**
     * Concatenate keys and values to url format and return url.
     * @param   string $url
     * @param   array $parameters
     * @return  string
     */
    private function createUrl($url, $parameters)
    {
        $url .= '?' . http_build_query($parameters);
        return $url;
    }

    /**
     * Executes request on link.
     * @param   string $url
     * @param   string $method
     * @param   array $postfields
     * @return  string
     */
    public function request($url, $method = 'GET', $postfields = array())
    {
			curl_setopt_array($this->ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_POST => ($method == 'POST'),
            CURLOPT_POSTFIELDS => $postfields,
           CURLINFO_HEADER_OUT => 1,
					 // CURLOPT_SAFE_UPLOAD => false,
            CURLOPT_URL => $url
        ));
				$res = curl_exec($this->ch);
				echo curl_error($this->ch);
				return $res;
    }
		
		public static function getTokenFromUrl($url){
			if( ($fragment = parse_url($url, PHP_URL_FRAGMENT) and preg_match('~access_token~', $fragment) ) ){
				return preg_replace('|^access_token=([^&]+)&.*$|', "$1", $fragment);
			}
			return $url;
		}

}

;










