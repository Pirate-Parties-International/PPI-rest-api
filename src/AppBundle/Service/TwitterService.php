<?php
namespace AppBundle\Service;

use Doctrine\ORM\EntityManager;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Validator\Constraints\DateTime;

use AppBundle\Command\ScraperCommand;
use AppBundle\Service\ScraperServices;

use TwitterAPIExchange;

class TwitterService extends ScraperServices 
{
    protected $em;
    private   $container;

    public function __construct(EntityManager $entityManager, Container $container) {
        $this->em = $entityManager;
        $this->container = $container;
        @set_exception_handler(array($scraper, 'exception_handler'));
    }


    /**
     * Queries Twitter for stats and tweets
     * @param  string $username
     * @param  string $code     party code
     * @return array
     */
    public function getTwitterData($username, $code, $full = null) {
        $settings = array(
            'oauth_access_token'        => $this->container->getParameter('tw_oauth_access_token'),
            'oauth_access_token_secret' => $this->container->getParameter('tw_oauth_access_token_secret'),
            'consumer_key'              => $this->container->getParameter('tw_consumer_key'),
            'consumer_secret'           => $this->container->getParameter('tw_consumer_secret')
        );

    //
    // Basic info and stats
    //
        $url = 'https://api.twitter.com/1.1/users/show.json';
        $getfield = '?screen_name='.str_replace("@", "", $username);
        $requestMethod = 'GET';

        $twitter = new TwitterAPIExchange($settings);
        $data = $twitter->setGetfield($getfield)
            ->buildOauth($url, $requestMethod)
            ->performRequest();

        if (empty($data)) {
            return false;
        }

        $data = json_decode($data);

        if (empty($data->followers_count)) {
            return false;
        }

        $out = [
            'description' => $data->description,
            'tweets'      => $data->statuses_count,
            'likes'       => $data->favourites_count,
            'followers'   => $data->followers_count,
            'following'   => $data->friends_count,
        ];
        echo "     + Info and stats... ok... total ".$out['tweets']." tweets found\n";

        $temp = $this->getTweetDetails($settings, $requestMethod, $username, $code, $full);
        $out['posts']  = isset($temp['posts'])  ? $temp['posts']  : null;
        $out['images'] = isset($temp['images']) ? $temp['images'] : null;
        $out['videos'] = isset($temp['videos']) ? $temp['videos'] : null;

        $timeCheck = $temp['timeCheck'];
        $imgCount  = array_key_exists('images', $out) ? count($out['images']) : 0;
        $vidCount  = array_key_exists('videos', $out) ? count($out['videos']) : 0;
        echo "...".count($out['posts'])." text posts, ".$imgCount." images and ".$vidCount." videos since ".date('d/m/Y', $timeCheck)." processed\n";
        return $out;
    }


    /**
     * Gets tweet details (inc. images and videos)
     * @param  array  $settings
     * @param  string $requetMethod
     * @param  string $username
     * @param  string $code
     * @param  bool   $full
     * @return int
     */
    public function getTweetDetails($settings, $requestMethod, $username, $code, $full) {
        $tweetUrl = 'https://api.twitter.com/1.1/statuses/user_timeline.json';
        $getfield = '?screen_name='.str_replace("@", "", $username).'&count=100';
        try {
            $twitter = new TwitterAPIExchange($settings);
            $tweetData = $twitter->setGetField($getfield)
                ->buildOauth($tweetUrl, $requestMethod)
                ->performRequest();
        } catch (\Exception $e) {
            echo $e->getMessage()."\n";
            $out['errors'][] = [$code => $e->getMessage()];
            return false;
        }

        $scraper = $this->container->get('ScraperServices');
        echo "     + Tweet details.... ";
        if (empty($tweetData)) {
            echo "not found\n";
            return false;
        } else {
            $tweetData = json_decode($tweetData);
            $timeLimit = $scraper->getTimeLimit('tw', 'T', $code, $full);
            $pageCount = 0;
            echo "page ";

            do { // process current page of results
                echo $pageCount .', ';

                foreach($tweetData as $item) {

                    $image  = null;
                    $twTime = \DateTime::createFromFormat('D M d H:i:s P Y', $item->created_at);
                    // original string e.g. 'Mon Sep 08 15:19:11 +0000 2014'

                    if (!empty($item->entities->media)) { // if tweet contains media
                        $media = $item->extended_entities->media;
                        foreach ($media as $photo) { // if tweet contains multiple images
                            $imgSrc = $photo->media_url.":small";
                            $imgId  = $photo->id;

                            if ($photo->type == 'video') {
                                $postType = 'videos';
                            } else { // if type == 'photo' or 'animated_gif'
                                $postType = 'images';
                            }

                            // save image to disk
                            $img = $scraper->saveImage('tw', $code, $imgSrc, $imgId);

                            $out[$postType][] = [
                                'postId'    => $item->id,
                                'postTime'  => $twTime, // DateTime
                                'postText'  => $item->text,
                                'postImage' => $img,
                                'postLikes' => $item->favorite_count,
                                'postData'  => [
                                    'id'         => $imgId,
                                    'posted'     => $twTime->format('Y-m-d H:i:s'), // string
                                    'text'       => $item->text,
                                    'image'      => $img,
                                    'img_source' => $imgSrc,
                                    'url'        => 'https://twitter.com/statuses/'.$item->id,
                                    'likes'      => $item->favorite_count,
                                    'retweets'   => $item->retweet_count
                                    ]
                                ];
                        }

                    } else { // if text only
                        $out['posts'][] = [
                            'postId'    => $item->id,
                            'postTime'  => $twTime, // DateTime
                            'postText'  => $item->text,
                            'postImage' => null,
                            'postLikes' => $item->favorite_count,
                            'postData'  => [
                                'id'       => $item->id,
                                'posted'   => $twTime->format('Y-m-d H:i:s'), // string
                                'text'     => $item->text,
                                'url'      => 'https://twitter.com/statuses/'.$item->id,
                                'likes'    => $item->favorite_count,
                                'retweets' => $item->retweet_count
                                ]
                            ];
                    }
                    // echo '.';
                }

                $timeCheck = $twTime->getTimestamp(); // check time of last tweet scraped

                try {
                    $limitData = null;
                    do { // check rate limit
                        $limitUrl  = 'https://api.twitter.com/1.1/application/rate_limit_status.json';
                        $limitData = json_decode($twitter->buildOauth($limitUrl, $requestMethod)->performRequest(), true);
                    } while (!isset($limitData['resources'])); // make sure we have a response before continuing

                    $limitCheck = $limitData['resources']['application']['/application/rate_limit_status'];
                    // echo "(".$limitCheck['remaining']." remaining, resetting at ".date('H:i:s', $limitCheck['reset']).") ";

                    if ($limitCheck['remaining'] < 2) { // give ourselves a little bit of wiggle room
                        echo "...Rate limit reached! Resuming at ".date('H:i:s', $limitCheck['reset'])."... ";
                        time_sleep_until($limitCheck['reset']);
                    }

                    // make new request to get next page of results
                    $nextField = '?screen_name='.str_replace("@", "", $username).'&max_id='.($item->id).'&count=100';
                    $tweetData = json_decode($twitter->setGetField($nextField)
                        ->buildOauth($tweetUrl, $requestMethod)
                        ->performRequest());
                } catch (\Exception $e) {
                    echo $e->getMessage()."\n";
                    $out['errors'][] = [$code => $e->getMessage()];
                    return false;
                }

                $pageCount++;

            } while ($timeCheck > $timeLimit && $pageCount < 100);
            // while tweet times are more recent than the limit as set above, up to 5000

        }
        $out['timeCheck'] = $timeCheck;
        return $out;
    }

}
