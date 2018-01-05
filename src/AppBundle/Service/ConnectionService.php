<?php
namespace AppBundle\Service;

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

    public function __construct(Container $container) {
        $this->container = $container;
        $this->log       = $this->container->get('logger');
        @set_exception_handler([$this, 'exception_handler']);
    }


    public function exception_handler($e) {
        if ($message == "Application request limit reached") {
            $this->getFbRateLimit();
        } else {
            $message = $e->getMessage();
            $this->log->error($message);
        }
    }


    /**
     * Throws a Facebook Throttle Exception to test error handling
     */
    public function testFbRateLimit() {
        $e = new FacebookThrottleException("Application request limit reached");
        // $e->setCode(4);
        // $e->setMessage("Application request limit reached");
        throw $e;
    }


    /**
     * Builds a new Facebook API object
     * @return object
     */
    public function getNewFacebook() {
        $settings = [
            'app_id'                => $this->container->getParameter('fb_app_id'),
            'app_secret'            => $this->container->getParameter('fb_app_secret'),
            'default_graph_version' => 'v2.7',
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
        $request = $this->fb->request('GET', $fbPageId, ['fields' => $fields]);

        try {
            $response = $this->fb->getClient()->sendRequest($request);
            // $this->testFbRateLimit();

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
            $response = $this->getFbRateLimit($request);

        } catch(\Exception $e) {
            if ($e->getMessage() == "Application request limit reached") {
                $response = $this->getFbRateLimit($request);
            } else {
                $this->log->error($fbPageId . " - Exception: " . $e->getMessage());
                return false;
            }
        }

        $graphNode = null;
        $graphNode = $response->getGraphNode();

        return $graphNode;
    }


    /**
     * Stops sending requests if the app hits Facebook's rate limit (untested)
     * @param  object $request
     * @return object
     */
    public function getFbRateLimit($request = null) {
        $connected = false;

        $this->log->warning(" - Facebook rate limit reached!");

        if (is_null($request)) {
            $request = $this->fb->request('GET', $this->fbPageId, ['fields' => 'engagement']);
        }

        do {
            try {
                $waitUntil = strtotime("+20 minutes");

                $this->log->notice("  - Please wait until " . date('H:i:s', $waitUntil) . "...");
                time_sleep_until($waitUntil);

                // $this->testFbRateLimit();

                $response = $this->fb->getClient()->sendRequest($request);
                $connected = true;

            } catch(\Exception $e) {
                $this->log->error(" - " . $e->getMessage());
            }
        } while ($connected == false);

        return $response;
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
    	// echo "\n" . $url . $field;

    	try {
		    $data = $tw
		    	->setGetfield($field)
		    	->buildOauth($url, $method)
		        ->performRequest();
		    return json_decode($data);

        } catch (\Exception $e) {
            $this->log->error($username . " - " . $e->getMessage());
            return false;
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
        } while (!isset($data['resources'])); // make sure we have a response before continuing

        $limitCheck = $data['resources']['application']['/application/rate_limit_status'];
        // $this->log->debug("       + (" . $limitCheck['remaining'] . " requests remaining, resetting at " . date('H:i:s', $limitCheck['reset']) . ") ");

        if ($limitCheck['remaining'] < 2) { // give ourselves a little bit of wiggle room
            $this->log->notice("  - Twitter rate limit reached! Resuming at " . date('H:i:s', $limitCheck['reset']) . "...");
            time_sleep_until($limitCheck['reset']);
        }
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
     * @return string
     */
    public function curl($url) {
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