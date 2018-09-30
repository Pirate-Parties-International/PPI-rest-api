<?php
namespace AppBundle\Services;

use Symfony\Component\DependencyInjection\Container;

use Facebook\Facebook;
use Facebook\Exceptions\FacebookSDKException;
use Facebook\Exceptions\FacebookResponseException;
use Facebook\Exceptions\FacebookThrottleException;
use Madcoda\Youtube;
use TwitterAPIExchange;

class ConnectionService
{
    private   $container;
    protected $log;

    protected $fb       = null;
    protected $fbPageId = null;
    protected $fbFields = null;

    public function __construct(Container $container) {
        $this->container = $container;
        $this->log       = $this->container->get('logger');
        @set_exception_handler([$this, 'exception_handler']);
    }


    /**
     * Handles exceptions that weren't caught by other functions
     * @param  object $e
     * @param  string $pageId <optional>
     * @return bool
     */
    public function exception_handler($e, $pageId = null, $fb = false) {
        $message = $e->getMessage();
        $code    = $e->getCode();

        if ($fb) {
            switch ($code) {
                case 4: // request limit reached
                    $this->log->error($code . ": " . $message);
                    $this->catchFbRateLimit();
                    break;
                case 28: // connection timeout
                case 35: // unknown SSL error
                    $this->log->warning($pageId . " - " . $code . ": " . $message);
                    return false;
                default:
                    $this->log->error($pageId . " - " . $code . ": " . $message);
                    return false;
            }
        } else {
            $this->log->error($pageId . " - " . $code . ": " . $message);
            return false;
        }
    }


    /**
     * Builds a new Facebook API object
     * @return object
     */
    public function getNewFacebook() {
        $settings = [
            'app_id'                => $this->container->getParameter('fb_app_id'),
            'app_secret'            => $this->container->getParameter('fb_app_secret'),
            'default_graph_version' => 'v2.8',
        ];

        $this->fb = new Facebook($settings);
        $this->fb->setDefaultAccessToken($this->container->getParameter('fb_access_token'));

        return $this->fb;
    }


    /**
     * Sends request to Facebook API and returns graph node
     * @param  string $fbPageId
     * @param  string $fields
     * @return object
     */
    public function getFbGraphNode($fbPageId, $fields) {
        $this->fbPageId = $fbPageId;
        $this->fbFields = $fields;

        $request = $this->fb->request('GET', $fbPageId, ['fields' => $fields]);

        try {
            $response = $this->fb->getClient()->sendRequest($request);
            $this->getFbRateLimit();

        } catch(Facebook\Exceptions\FacebookResponseException $e) {
            // When Graph returns an error
            $this->log->error($fbPageId . " - Graph returned an error: " . $e->getMessage());
            exit;

        } catch(Facebook\Exceptions\FacebookSDKException $e) {
            // When validation fails or other local issues
            $this->log->error($fbPageId . " - Facebook SDK returned an error: " . $e->getMessage());
            exit;

        } catch(Facebook\Exceptions\FacebookThrottleException $e) {
            // When the app hits the rate limit
            $response = $this->catchFbRateLimit();

        } catch(\Exception $e) {
            if ($e->getCode() == 100) {
                $this->log->warning($fbPageId . " - Error 100: " . $e->getMessage());
                return false;
            } else {
                $handleError = $this->exception_handler($e, $fbPageId, true);
                if ($handleError == false) {
                    return false;
                }
            }
        }

        $graphNode = null;
        $graphNode = $response->getGraphNode();

        return $graphNode;
    }


    /**
     * Checks the Facebook rate limit status
     * @return bool
     */
    public function getFbRateLimit() {
        $handler = (array) $this->fb->getClient()->getHttpClientHandler();
        $json    = json_encode($handler);
        $json    = str_replace('\u0000*\u0000', '', $json);
        $array   = json_decode($json, true);
        $string  = $array['rawResponse'];

        $callPos    = strpos($string, "x-app-usage");
        $subString  = substr($string, $callPos+27, 5);
        $callEnd    = strpos($subString, ',');
        $callString = substr($subString, 0, $callEnd);
        $callCount  = (int) $callString;

        if (!is_int($callCount) || $callCount == 0) {
            $this->log->warning("   - FB call count was not an int, it was '" . $callString . "'.");
            return false;
        }

        if (is_int($callCount / 5) || $callCount > 95) {
            $this->log->debug("     + (" . $callCount . "/100 requests made)");
        }

        if ($callCount > 95) {
            $waitUntil = strtotime("+10 minutes");

            if ($callCount < 100) {
                $callStatus = 'approaching';
            } else if ($callCount == 100) {
                $callStatus = 'reached';
            } else {
                $callStatus = 'exceeded';
            }

            $this->log->notice("  - Facebook rate limit " . $callStatus . "! Retrying at " . date('H:i:s', $waitUntil) . "...");
            time_sleep_until($waitUntil);
            return false;
        }

        return true;
    }


    /**
     * Stops sending requests if the app hits Facebook's rate limit
     * @return null
     */
    public function catchFbRateLimit() {
        $continue = false;

        do {
            try {
                $continue = $this->getFbRateLimit();
            } catch(\Exception $e) {
                $this->log->error("     - " . $e->getMessage());
            }
        } while ($continue == false);

        $this->getFbGraphNode($this->fbPageId, $this->fbFields);
    }


    /**
     * Builds a new Twitter API object
     * @return object
     */
    public function getNewTwitter() {
        $settings = [
            'oauth_access_token'        => $this->container->getParameter('tw_oauth_access_token'),
            'oauth_access_token_secret' => $this->container->getParameter('tw_oauth_access_token_secret'),
            'consumer_key'              => $this->container->getParameter('tw_consumer_key'),
            'consumer_secret'           => $this->container->getParameter('tw_consumer_secret')
        ];

        $tw = new TwitterAPIExchange($settings);

        return $tw;
    }


    /**
     * Sends request to Twitter API
     * @param  object $tw
     * @param  string $username
     * @param  bool   $tweets <optional>
     * @param  string $maxId  <optional>
     * @return object
     */
    public function getTwRequest($tw, $username, $tweets = false, $maxId = null) {
    	$method = 'GET';
		$field  = '?screen_name=' . str_replace("@", "", $username);

    	if (!$tweets) {
			$url    = 'https://api.twitter.com/1.1/users/show.json';
		} else {
	        $url    = 'https://api.twitter.com/1.1/statuses/user_timeline.json';
	        $field .= '&tweet_mode=extended&count=100';
	        $field .= $maxId ? ('&max_id=' . $maxId) : null;
    	}

    	try {
		    $data = $tw
		    	->setGetfield($field)
		    	->buildOauth($url, $method)
		        ->performRequest();
		    return json_decode($data);

        } catch (\Exception $e) {
            if ($e->getCode() == 4) { // if the app hits the rate limit
                $this->catchTwRateLimit($tw, $username, $tweets, $maxId);
            } else {
                $this->log->error($username . " - " . $e->getMessage());
                return false;
            }
        }
    }


    /**
     * Checks the Twitter rate limit status
     * @param  object $tw
     */
    public function getTwRateLimit($tw) {
        $data   = null;
        $method = 'GET';
        $url    = 'https://api.twitter.com/1.1/application/rate_limit_status.json';

        do { // check rate limit
            $response = $tw
                ->buildOauth($url, $method)
                ->performRequest();
            $data = json_decode($response, true);
        } while (!isset($data['resources']['application'])); // make sure we have a response before continuing

        $limitCheck = $data['resources']['application']['/application/rate_limit_status'];
        $this->log->debug("       + (" . $limitCheck['remaining'] . " requests remaining, resetting at " . date('H:i:s', $limitCheck['reset']) . ") ");

        if ($limitCheck['remaining'] < 2) { // give ourselves a little bit of wiggle room
            $this->log->notice("  - Twitter rate limit reached! Resuming at " . date('H:i:s', $limitCheck['reset']) . "...");
            time_sleep_until($limitCheck['reset']);
        }
    }


    /**
     * Stops sending requests if the app hits Twitter's rate limit
     * @param  object $tw
     * @param  string $username
     * @param  bool   $tweets
     * @param  int    $maxId
     */
    public function catchTwRateLimit($tw, $username, $tweets, $maxId) {
        $this->log->warning(" - Twitter rate limit reached!");

        $waitUntil = strtotime("+10 minutes");
        $this->log->notice("       - Resetting rate limit. Please wait until " . date('H:i:s', $waitUntil) . "...");
        time_sleep_until($waitUntil);

        $this->getTwRequest($tw, $username, $tweets, $maxId);
    }


    /**
     * Builds a new Google/Youtube API object
     * @param  string $googleId
     * @param  bool   $yt
     * @return object
     */
    public function getNewGoogle($googleId, $yt = false) {
        $apikey = $this->container->getParameter('gplus_api_key');

        if ($yt) {
	        $youtube = new Youtube(['key' => $apikey]);
    	    return $youtube;
		}

		$url = 'https://www.googleapis.com/plus/v1/people/';
		$url .= $googleId . '?key=' . $apikey;
        $google = $this->curl($url, $googleId);

        return json_decode($google);
    }


    /**
     * Sends request via cURL
     * @param  string $url
     * @param  string $googleId <optional>
     * @return string
     */
    public function curl($url, $googleId = null) {
        // Get cURL resource
        $curl = curl_init();
        // Set some options - we are passing in a useragent too here
        curl_setopt_array($curl, array(
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_URL => $url,
            CURLOPT_USERAGENT => 'PAPI'
        ));

        $connected = false;
        $tryCount  = 0;

        do {
            try {
                // Send the request & save response
                $response  = curl_exec($curl);
                $connected = true;
            } catch (\Exception $e) {
                $this->log->error($googleId . " - " . $e->getMessage());
                $tryCount++;
                return false;
            }
        } while ($connected == false && $tryCount < 5);

        // Close request to clear up some resources
        curl_close($curl);

        return $response;
    }

}