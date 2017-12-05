<?php
namespace AppBundle\Service;

use Symfony\Component\DependencyInjection\Container;

use Facebook\Facebook;
use Facebook\FacebookSDKException;
use Facebook\FacebookResponseException;

use TwitterAPIExchange;

class ConnectionService extends ScraperServices
{
	protected $parent;
    // protected $twRequestMethod = 'GET';
    private   $container;

    public function __construct(Container $container) {
        $this->container = $container;
        $this->parent    = $this->container->get('ScraperServices');
        @set_exception_handler([$this->parent, 'exception_handler']);
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

        $fb = new Facebook($settings);
        $fb->setDefaultAccessToken($this->container->getParameter('fb_access_token'));

        return $fb;
    }


    /**
     * Sends request to Facebook API and returns graph node
     * @param  object $fb
     * @param  string $fbPageId
     * @param  string $fields
     * @return object
     */
    public function getFbGraphNode($fb, $fbPageId, $fields) {
        $request = $fb->request('GET', $fbPageId, ['fields' => $fields]);

        try {
            $response = $fb->getClient()->sendRequest($request);

        } catch(Facebook\Exceptions\FacebookResponseException $e) {
            // When Graph returns an error
            echo 'Graph returned an error: ' . $e->getMessage() . "\n";
            exit;

        } catch(Facebook\Exceptions\FacebookSDKException $e) {
            // When validation fails or other local issues
            echo 'Facebook SDK returned an error: ' . $e->getMessage() . "\n";
            exit;

        } catch(\Exception $e) {
            echo $fbPageId . " - Exception: " . $e->getMessage() . "\n";
            return false;
        }

        $graphNode = $response->getGraphNode();

        return $graphNode;
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
		$field = '?screen_name='.str_replace("@", "", $username);
    	$requestMethod = 'GET';

    	if (!$tweets) {
			$url   = 'https://api.twitter.com/1.1/users/show.json';
		} else {
	        $url   = 'https://api.twitter.com/1.1/statuses/user_timeline.json';
	        $id    = $maxId ? '&max_id=' . $maxId : null;
	        $field .= '&tweet_mode=extended&count=1000' . $id;
    	}
    	// echo "\n" . $url . $field;

    	try {
		    $data = $tw
		    	->setGetfield($field)
		    	->buildOauth($url, $requestMethod)
		        ->performRequest();
		    return json_decode($data);

        } catch (\Exception $e) {
            echo $e->getMessage()."\n";
            return false;
        }
    }


    /**
     * Checks the Twitter rate limit status
     * @param  object $tw
     * @return array
     */
    public function getTwRateLimit($tw) {
        $limitData = null;
        $requestMethod = 'GET';

        do { // check rate limit
	        $limitUrl      = 'https://api.twitter.com/1.1/application/rate_limit_status.json';
	        $limitResponse = $tw
	        	->buildOauth($limitUrl, $requestMethod)
	        	->performRequest();

	        $limitData = json_decode($limitResponse, true);

        } while (!isset($limitData['resources'])); // make sure we have a response before continuing

        $limitCheck = $limitData['resources']['application']['/application/rate_limit_status'];
        // echo "(" . $limitCheck['remaining'] . " remaining, resetting at " . date('H:i:s', $limitCheck['reset']) . ") ";

        if ($limitCheck['remaining'] < 2) { // give ourselves a little bit of wiggle room
            echo "...Rate limit reached! Resuming at " . date('H:i:s', $limitCheck['reset']) . "... ";
            time_sleep_until($limitCheck['reset']);
        }

    	return $limitCheck;
    }


    /**
     * Sends request via cUrl
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
                echo $e->getMessage() . "\n";
                $tryCount++;
                return false;
            }
        } while ($connected == false && $tryCount < 5);

        // Close request to clear up some resources
        curl_close($curl);

        return $response;
    }

}