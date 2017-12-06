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

class TwitterService extends ScraperServices 
{
    protected $em;
    protected $parent;
    protected $connect;
    private   $container;

    public function __construct(EntityManager $entityManager, Container $container) {
        $this->em        = $entityManager;
        $this->container = $container;
        $this->parent    = $this->container->get('ScraperServices');
        $this->connect   = $this->container->get('ConnectionService');
        @set_exception_handler(array($this->parent, 'exception_handler'));
    }


    /**
     * Queries Twitter for stats and tweets
     * @param  string $username
     * @param  string $partyCode
     * @param  bool   $scrapeFull
     * @return array
     */
    public function getTwitterData($username, $partyCode, $scrapeFull = false) {
        $tw   = $this->connect->getNewTwitter();
        $data = $this->connect->getTwRequest($tw, $username);

        echo "     + Info and stats... ";
        if (empty($data) || empty($data->followers_count)) {
            echo "not found\n";
            return false;
        }

        $out = [
            'description' => $data->description,
            'tweets'      => $data->statuses_count,
            'likes'       => $data->favourites_count,
            'followers'   => $data->followers_count,
            'following'   => $data->friends_count,
        ];
        echo "ok... total " . $out['tweets'] . " tweets found\n";

        $temp = $this->getTweetDetails($tw, $username, $partyCode, $scrapeFull);
        $out['posts']  = isset($temp['posts'])  ? $temp['posts']  : null;
        $out['images'] = isset($temp['images']) ? $temp['images'] : null;
        $out['videos'] = isset($temp['videos']) ? $temp['videos'] : null;

        $timeCheck = $temp['timeCheck'];
        $imgCount  = array_key_exists('images', $out) ? count($out['images']) : 0;
        $vidCount  = array_key_exists('videos', $out) ? count($out['videos']) : 0;
        echo "..." . count($out['posts']) . " text posts, " . $imgCount . " images and " . $vidCount . " videos since " . date('d/m/Y', $timeCheck) . " processed\n";
 
        return $out;
    }


    /**
     * Gets tweet details (inc. images and videos)
     * @param  array  $settings
     * @param  string $requetMethod
     * @param  string $username
     * @param  string $partyCode
     * @param  bool   $scrapeFull
     * @return int
     */
    public function getTweetDetails($tw, $username, $partyCode, $scrapeFull = false) {
        $tweetData = $this->connect->getTwRequest($tw, $username, true);

        echo "     + Tweet details.... ";
        if (empty($tweetData)) {
            echo "not found\n";
            return false;
        }

        $timeLimit = $this->parent->getTimeLimit('tw', 'T', $partyCode, $scrapeFull);
        $pageCount = 0;
        echo "page ";

        do { // process current page of results
            echo $pageCount .', ';

            foreach($tweetData as $item) {

                $image  = null;
                $twTime = \DateTime::createFromFormat('D M d H:i:s P Y', $item->created_at);
                // original string e.g. 'Mon Sep 08 15:19:11 +0000 2014'

                if (!empty($item->full_text)) {
                    $twText = $item->full_text;
                } else if (!empty($item->text)) {
                    $twText = $item->text;
                } else $twText = null;

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
                        $img = $this->container->get('ImageService')
                            ->saveImage('tw', $partyCode, $imgSrc, $imgId);

                        $out[$postType][] = [
                            'postId'    => $item->id,
                            'postTime'  => $twTime, // DateTime
                            'postText'  => $twText,
                            'postImage' => $img,
                            'postLikes' => $item->favorite_count,
                            'postData'  => [
                                'id'         => $imgId,
                                'posted'     => $twTime->format('Y-m-d H:i:s'), // string
                                'text'       => $twText,
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
                        'postText'  => $twText,
                        'postImage' => null,
                        'postLikes' => $item->favorite_count,
                        'postData'  => [
                            'id'       => $item->id,
                            'posted'   => $twTime->format('Y-m-d H:i:s'), // string
                            'text'     => $twText,
                            'url'      => 'https://twitter.com/statuses/'.$item->id,
                            'likes'    => $item->favorite_count,
                            'retweets' => $item->retweet_count
                            ]
                        ];
                }
                // echo '.';
            }

            $timeCheck  = $twTime->getTimestamp(); // check time of last tweet scraped
            $this->connect->getTwRateLimit($tw);

            // make new request to get next page of results
            $tweetData = $this->connect->getTwRequest($tw, $username, true, $item->id);
            $pageCount++;

        } while ($timeCheck > $timeLimit && $pageCount < 100);
        // while tweet times are more recent than the limit as set above, up to 5000

        $out['timeCheck'] = $timeCheck;
        return $out;
    }

}
