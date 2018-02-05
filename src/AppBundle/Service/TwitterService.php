<?php
namespace AppBundle\Service;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Validator\Constraints\DateTime;

use AppBundle\Command\ScraperCommand;
use AppBundle\Entity\Metadata;
use AppBundle\Entity\Statistic;
use AppBundle\Entity\SocialMedia;

class TwitterService
{
    private   $container;
    protected $connect;
    protected $db;
    protected $log;

    protected $partyCode;
    protected $twUsername;
    protected $scrapeFull;
    protected $tw;

    public function __construct(Container $container) {
        $this->container = $container;
        $this->connect   = $this->container->get('ConnectionService');
        $this->db        = $this->container->get('DatabaseService');
        $this->log       = $this->container->get('logger');
        @set_exception_handler(array($this->connect, 'exception_handler'));
    }


    /**
     * Queries Twitter for stats and tweets
     * @param  string $partyCode
     * @param  string $twUsername
     * @param  bool   $scrapeFull
     * @return array
     */
    public function getTwitterData($partyCode, $twUsername, $scrapeData = false, $scrapeFull = false) {
        $this->scrapeFull = $scrapeFull;
        $this->partyCode  = $partyCode;
        $this->twUsername = $twUsername;
        $this->tw         = $this->connect->getNewTwitter();

        $data = $this->connect->getTwRequest($this->tw, $twUsername);

        if (!isset($data->statuses_count)) {
            $this->log->notice("   - Twitter data not found for " . $this->partyCode);
            return false;
        }

        $out = $this->getTwStats($data);
        if (!empty($out)) {
            $this->log->info("    + Info and stats... ok");
            $this->log->info("      + Total " . $out['tweets'] . " tweets found");
        }

        if ($scrapeData == 'info') {
            return $out;
        }

        $temp = $this->getTweets();
        $out['posts']  = isset($temp['posts'])  ? $temp['posts']  : 0;
        $out['images'] = isset($temp['images']) ? $temp['images'] : 0;
        $out['videos'] = isset($temp['videos']) ? $temp['videos'] : 0;

        $timeCheck = $temp['timeCheck'];
        $this->log->info("      + " . $out['posts'] . " text posts, " . $out['images'] . " images and " . $out['videos'] . " videos since " . date('d/m/Y', $timeCheck) . " processed");
 
        return $out;
    }


    /**
     * Retrieves Twitter account statistics
     * @param  object $data
     * @return array
     */
    public function getTwStats($data) {
        $array = [];

        if (isset($data->description)) {
            $this->db->addMeta(
                $this->partyCode,
                Metadata::TYPE_TWITTER_INFO,
                $data->description
            );
            $array['description'] = true;
        }

        if (isset($data->favourites_count)) {
            $this->db->addStatistic(
                $this->partyCode,
                Statistic::TYPE_TWITTER,
                Statistic::SUBTYPE_LIKES,
                $data->favourites_count
            );
            $array['likes'] = true;
        }

        if (isset($data->followers_count)) {
            $this->db->addStatistic(
                $this->partyCode,
                Statistic::TYPE_TWITTER,
                Statistic::SUBTYPE_FOLLOWERS,
                $data->followers_count
            );
            $array['followers'] = true;
        }

        if (isset($data->friends_count)) {
            $this->db->addStatistic(
                $this->partyCode,
                Statistic::TYPE_TWITTER,
                Statistic::SUBTYPE_FOLLOWING,
                $data->friends_count
            );
            $array['following'] = true;
        }

        if (isset($data->statuses_count)) {
            $this->db->addStatistic(
                $this->partyCode,
                Statistic::TYPE_TWITTER,
                Statistic::SUBTYPE_POSTS,
                $data->statuses_count
            );
            $array['tweets'] = $data->statuses_count;
        }

        return $array;
    }

    /**
     * Processes tweets
     * @return array
     */
    public function getTweets() {
        $tweetData = $this->connect->getTwRequest($this->tw, $this->twUsername, true);

        if (empty($tweetData)) {
            $this->log->notice("    - Tweet details not found for " . $this->partyCode);
            return false;
        }

        $this->log->info("    + Getting tweet details...");

        $pageCount = 0;
        $txtCount  = 0;
        $imgCount  = 0;
        $vidCount  = 0;
        $loopCount = 0;
        $temp      = [];

        $timeLimit = $this->db->getTimeLimit($this->partyCode, 'tw', 'T', $this->scrapeFull);

        do { // process current page of results
            $this->log->debug("       + Page " . $pageCount);

            foreach($tweetData as $item) {
                $id = $item->id;

                if (in_array($id, $temp, true)) {
                    // if tweet was already scraped this session
                    $loopCount++;
                    continue;
                }

                $twTime = \DateTime::createFromFormat('D M d H:i:s P Y', $item->created_at);
                // original string e.g. 'Mon Sep 08 15:19:11 +0000 2014'

                if (empty($item->entities->media)) {
                    $temp[$id] = $this->getTweetDetails($item, $twTime);
                    $txtCount++;

                } else {
                    $media = $this->getMedia($item, $twTime);

                    $imgCount += $media['imgCount'];
                    $vidCount += $media['vidCount'];

                    foreach ($media as $item) {
                        $img        = $item['img'];
                        $temp[$img] = $item;
                    }
                }
            }

            $timeCheck = $twTime->getTimestamp(); // check time of last tweet scraped
            $this->connect->getTwRateLimit($this->tw);

            // make new request to get next page of results
            $tweetData = $this->connect->getTwRequest($this->tw, $this->twUsername, true, $id);
            $pageCount++;

        } while ($timeCheck > $timeLimit && $pageCount < 100);
        // while tweet times are more recent than the limit as set above, up to 5000

        if ($loopCount > 0) {
            $this->log->warning("     - Tweet scraping for " . $this->partyCode . " looped " . $loopCount . " times");
        }

        $this->db->processSocialMedia($temp);

        $out['posts']     = $txtCount;
        $out['images']    = $imgCount;
        $out['videos']    = $vidCount;
        $out['timeCheck'] = $timeCheck;
        return $out;
    }


    /**
     * Retrieves the details of a tweet
     * @param  object $item
     * @param  object $twTime
     * @return string
     */
    public function getTweetDetails($item, $twTime) {
        $twText   = $this->getTwText($item);
        $rtStatus = $this->getRtStatus($item);

        $allData = [
            'id'       => $item->id,
            'posted'   => $twTime->format('Y-m-d H:i:s'), // string
            'text'     => $twText,
            'url'      => 'https://twitter.com/statuses/' . $item->id,
            'likes'    => $item->favorite_count,
            'retweets' => $item->retweet_count,
            'reply_to' => $rtStatus
            ];

        $out = [
            'code'    => $this->partyCode,
            'type'    => SocialMedia::TYPE_TWITTER,
            'subtype' => SocialMedia::SUBTYPE_TEXT,
            'id'      => $item->id,
            'time'    => $twTime, // DateTime
            'text'    => $twText,
            'img'     => null,
            'likes'   => $item->favorite_count,
            'allData' => $allData
            ];

        return $out;
    }


    /**
     * Determines the type of media that a tweet contains
     * @param  object $item
     * @param  object $twTime
     * @return array
     */
    public function getMedia($item, $twTime) {
        $imgCount = 0;
        $vidCount = 0;
        $out      = [];

        $media = $item->extended_entities->media;

        foreach ($media as $photo) {
            $id = $photo->media_url;

            if (in_array($id, $out, true)) {
                // if tweet was already scraped this session
                $loopCount++;
                continue;
            }

            if ($photo->type == 'video') {
                $subType = SocialMedia::SUBTYPE_VIDEO;
                $vidCount++;

            } else { // if type == 'photo' or 'animted_gif'
                $subType = SocialMedia::SUBTYPE_IMAGE;
                $imgCount++;
            }

            $out[$id] = $this->getMediaDetails($item, $twTime, $photo, $subType);
        }

        $out['imgCount'] = $imgCount;
        $out['vidCount'] = $vidCount;
        return $out;
    }


    /**
     * Retrieves the details of an image or video
     * @param  object $item
     * @param  object $twTime
     * @param  string $photo
     * @return array
     */
    public function getMediaDetails($item, $twTime, $photo, $subType) {
        $twText   = $this->getTwText($item);
        $rtStatus = $this->getRtStatus($item);

        $imgSrc = $photo->media_url . ":small";
        $imgId  = $photo->id;
        $img    = $this->container
            ->get('ImageService')
            ->saveImage('tw', $this->partyCode, $imgSrc, $imgId);

        $allData = [
            'id'         => $imgId,
            'posted'     => $twTime->format('Y-m-d H:i:s'), // string
            'text'       => $twText,
            'image'      => $img,
            'img_source' => $imgSrc,
            'url'        => 'https://twitter.com/statuses/' . $item->id,
            'likes'      => $item->favorite_count,
            'retweets'   => $item->retweet_count,
            'reply_to'   => $rtStatus
            ];

        $out = [
            'code'    => $this->partyCode,
            'type'    => SocialMedia::TYPE_TWITTER,
            'subtype' => $subType,
            'id'      => $item->id,
            'time'    => $twTime, // DateTime
            'text'    => $twText,
            'img'     => $img,
            'likes'   => $item->favorite_count,
            'allData' => $allData
            ];

        return $out;
    }


    /**
    * Returns text field of a tweet
    * @param  object $item
    * @return string
    */
    public function getTwText($item) {
        if (!empty($item->full_text)) {
            return $item->full_text;
        }

        if (!empty($item->text)) {
            return $item->text;
        }

        return null;
    }


    /**
     * Checks if a tweet is a retweet or reply
     * @param  object $item
     * @return string
     */
    public function getRtStatus($item) {
        if (isset($item->retweeted_status)) {
            return ['retweet' => $item->retweeted_status->id];
        }

        if (isset($item->quoted_status_id)) {
            return ['reply' => $item->quoted_status_id];
        }

        return null;
    }

}
