<?php
namespace AppBundle\Service;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Console\Output\OutputInterface;

use AppBundle\Command\ScraperCommand;
use AppBundle\Entity\SocialMedia;

class FacebookService
{
    private   $container;
    protected $connect;
    protected $db;
    protected $images;
    protected $log;
    protected $stats;

    protected $partyCode;
    protected $fbPageId;
    protected $scrapeFull;
    protected $fb;

    public function __construct(Container $container) {
        $this->container = $container;
        $this->connect   = $this->container->get('ConnectionService');
        $this->db        = $this->container->get('DatabaseService');
        $this->images    = $this->container->get('ImageService');
        $this->log       = $this->container->get('logger');
        $this->stats     = $this->container->get('FbStatService');
        @set_exception_handler([$this->db, 'exception_handler']);
    }


    /**
     * Queries for stats, posts, images and events
     * @param  string $partyCode
     * @param  string $fbPageId
     * @param  string $scrapeData
     * @param  bool   $scrapeFull
     * @return array
     */
    public function getFBData($partyCode, $fbPageId, $scrapeData = null, $scrapeFull = false) {
        $this->scrapeFull = $scrapeFull;
        $this->partyCode  = $partyCode;
        $this->fbPageId   = $fbPageId;
        $this->fb         = $this->connect->getNewFacebook();

        $graphNode = $this->connect->getFbGraphNode($this->fb, $this->fbPageId, 'engagement');

        if (empty($graphNode)) {
            return false;
        }

        $requestFields = [
            'basic'        => 'cover,engagement,talking_about_count,about,emails,single_line_address',
            'postStats'    => 'posts.limit(100){id}',
            'imageStats'   => 'albums{count}',
            'videoStats'   => 'videos.limit(100){id}',
            'eventStats'   => 'events.limit(100){id}',
            'postDetails'  => 'posts.limit(50){id,type,permalink_url,message,story,link,name,caption,picture,object_id,created_time,updated_time,shares,likes.limit(0).summary(true),reactions.limit(0).summary(true),comments.limit(0).summary(true)}',
            'imageDetails' => 'albums{id,name,photo_count,photos{created_time,updated_time,picture,source,link,name,likes.limit(0).summary(true),reactions.limit(0).summary(true),comments.limit(0).summary(true),sharedposts.limit(0).summary(true)}}',
            'eventDetails' => 'events{start_time,updated_time,name,cover,description,place,attending_count,interested_count,comments.limit(0).summary(true)}'
        ];

        if ($scrapeData == null || $scrapeData == 'info') {
            $this->stats->setVariables($partyCode, $fbPageId, $this->fb);
            $out = $this->stats->getPageInfo($requestFields['basic']);
            $out['postCount']  = $this->stats->getPostCount($requestFields['postStats']);
            $out['videoCount'] = $this->stats->getVideoCount($requestFields['videoStats']);
        }

        if ($scrapeData == 'info') {
            $out['imageCount'] = $this->stats->getImageCount($requestFields['imageStats']);
            $out['eventCount'] = $this->stats->getEventCount($requestFields['eventStats']);
        }

        if ($scrapeData == null || $scrapeData == 'posts') {
            $temp = $this->getPosts($requestFields['postDetails']);
            $out['posts']  = isset($temp['posts'])  ? $temp['posts']  : null;
            $out['videos'] = isset($temp['videos']) ? $temp['videos'] : null;
        }

        if ($scrapeData == null || $scrapeData == 'images') {
            $temp = $this->getImages($requestFields['imageDetails']);
            $out['imageCount'] = isset($temp['imageCount']) ? $temp['imageCount'] : null;
            $out['images']     = isset($temp['images'])     ? $temp['images']     : null;
        }

        if ($scrapeData == null || $scrapeData == 'events') {
            $temp = $this->getEvents($requestFields['eventDetails']);
            $out['eventCount'] = isset($temp['eventCount']) ? $temp['eventCount'] : null;
            $out['events']     = isset($temp['events'])     ? $temp['events']     : null;
        }

        return $out;
    }


    /**
     * Processes text posts (inc. videos)
     * @param  string $requestFields
     * @return array
     */
    public function getPosts($requestFields) {
        $graphNode = $this->connect->getFbGraphNode($this->fb, $this->fbPageId, $requestFields);

        if (empty($graphNode) || is_null($graphNode->getField('posts'))) {
            $this->log->notice("    - Facebook posts not found for " . $this->partyCode);
            return false;
        }

        $this->log->info("    + Getting post details...");
        $fdPosts   = $graphNode->getField('posts');
        $timeLimit = $this->db->getTimeLimit('fb', 'T', $this->partyCode, $this->scrapeFull);

        $pageCount = 0;
        $txtCount  = 0;
        $vidCount  = 0;

        do {
            $this->log->debug("       + Page " . $pageCount);

            foreach ($fdPosts as $key => $post) {
                $type = $post->getField('type');
                // types = 'status', 'link', 'photo', 'video', 'event'

                if ($type == 'photo' || $type == 'event') {
                    continue; // get photos and events separately to get all details
                } else if ($type == 'video') {
                    $subType = SocialMedia::SUBTYPE_VIDEO;
                    $vidCount++;
                } else {
                    $subType = SocialMedia::SUBTYPE_TEXT;
                    $txtCount++;
                }

                $this->getPostDetails($post, $subType);
            }

            $timeCheck = $post->getField('created_time')->getTimestamp(); // check time of last scraped post
            $pageCount++;

        } while ($timeCheck > $timeLimit && $fdPosts = $this->fb->next($fdPosts));
        // while next page is not null and within our time limit

        $out['posts']  = $txtCount;
        $out['videos'] = $vidCount;
        $this->log->info("      + " . $txtCount . " text posts and " . $vidCount . " videos since " . date('d/m/Y', $timeCheck) . " processed");

        return (isset($out)) ? $out : null;
    }


    /**
     * Retrieves the details of a text post or video
     * @param  object $post
     * @param  string $subType
     * @return null
     */
    public function getPostDetails($post, $subType) {
        $text   = !empty($post->getField('message')) ? $post->getField('message') : $post->getField('story');
        $imgSrc = $this->images->getFbExtImageSource($post);
        $img    = isset($imgSrc['src']) ? $this->images->saveImage('fb', $this->partyCode, $imgSrc['src'], $post->getField('id'), $imgSrc['bkp']) :  null;

        $likeCount     = $this->stats->getStatCount($post->getField('likes'));
        $reactionCount = $this->stats->getStatCount($post->getField('reactions'));
        $commentCount  = $this->stats->getStatCount($post->getField('comments'));
        $shareCount    = !empty($post->getField('shares')) ? json_decode($post->getField('shares')->getField('count'), true) : null;

        $allData = [
            'id'         => $post->getField('id'),
            'posted'     => $post->getField('created_time')->format('Y-m-d H:i:s'), // string
            'updated'    => $post->getField('updated_time')->format('Y-m-d H:i:s'), // string
            'text'       => $text,
            'image'      => $img,
            'img_source' => $imgSrc['src'],
            'link'       => [
                'url'        => $post->getField('link'),
                'name'       => $post->getField('name'),
                'caption'    => $post->getField('caption'),
                ],
            'url'        => $post->getField('permalink_url'),
            'likes'      => $likeCount,
            'reactions'  => $reactionCount,
            'comments'   => $commentCount,
            'shares'     => $shareCount
            ];

        $this->db->addSocial(
            $this->partyCode,
            SocialMedia::TYPE_FACEBOOK,
            $subType,
            $post->getField('id'),
            $post->getField('updated_time'), // DateTime
            $text,
            $img,
            $reactionCount,
            $allData
        );
    }

    /**
     * Processes images
     * @param  string $requestFields
     * @return array
     */
    public function getImages($requestFields) {
        $graphNode = $this->connect->getFbGraphNode($this->fb, $this->fbPageId, $requestFields);

        if (empty($graphNode) || is_null($graphNode->getField('albums'))) {
            $this->log->notice("    - Facebook images not found for " . $this->partyCode);
            return false;
        }

        $this->log->info("    + Getting image details...");
        $fdAlbums  = $graphNode->getField('albums');
        $timeLimit = $this->db->getTimeLimit('fb', 'I', $this->partyCode, $this->scrapeFull);

        $pageCount = 0;
        $imgCount  = 0;

        foreach ($fdAlbums as $key => $album) {
            $photoCount[] = $album->getField('photo_count');
            $fdPhotos     = $album->getField('photos');

            if (empty($fdPhotos)) {
                continue;
            }

            do {
                $this->log->debug("       + Page " . $pageCount);
                foreach ($fdPhotos as $key => $photo) {
                    $this->getImageDetails($photo, $album);
                    $imgCount++;
                }

                $timeCheck = $photo->getField('updated_time')->getTimestamp(); // check time of last scraped post
                $pageCount++;

            } while ($timeCheck > $timeLimit && $fdPhotos = $this->fb->next($fdPhotos));
            // while next page is not null and within our time limit
        }

        $out['imageCount'] = array_sum($photoCount);
        $out['images']     = $imgCount;
        $this->log->info("      + " . $out['imageCount'] . " images found, " . $imgCount . " since " . date('d/m/Y', $timeCheck) . " processed");

        return $out;
    }


    /**
     * Retrieves the details of an image
     * @param  object $photo
     * @param  object $album
     * @return null
     */
    public function getImageDetails($photo, $album) {
        $imgSrc = $this->images->getFbImageSource($this->fb, $photo->getField('id')); // ~480x480 (or closest)
        $imgBkp = $photo->getField('picture'); // 130x130 thumbnail
        $img    = $this->images->saveImage('fb', $this->partyCode, $imgSrc, $photo->getField('id'), $imgBkp);

        $likeCount     = $this->stats->getStatCount($photo->getField('likes'));
        $reactionCount = $this->stats->getStatCount($photo->getField('reactions'));
        $commentCount  = $this->stats->getStatCount($photo->getField('comments'));
        $shareCount    = count(json_decode($photo->getField('sharedposts'), true));

        $allData = [
            'id'         => $photo->getField('id'),
            'posted'     => $photo->getField('created_time')->format('Y-m-d H:i:s'), // string
            'updated'    => $photo->getField('updated_time')->format('Y-m-d H:i:s'), // string
            'text'       => $photo->getField('name'),
            'image'      => $img,
            'img_source' => $imgSrc,
            'url'        => $photo->getField('link'),
            'album'      => [
                'name'       => $album->getField('name'),
                'id'         => $album->getField('id'),
                ],
            'likes'      => $likeCount,
            'reactions'  => $reactionCount,
            'comments'   => $commentCount,
            'shares'     => $shareCount
        ];

        $this->db->addSocial(
            $this->partyCode,
            SocialMedia::TYPE_FACEBOOK,
            SocialMedia::SUBTYPE_IMAGE,
            $photo->getField('id'),
            $photo->getField('updated_time'), // DateTime
            $photo->getField('name'),
            $img,
            $reactionCount,
            $allData
        );
    }


    /**
     * Processes events
     * @param  string $requestFields
     * @return array
     */
    public function getEvents($requestFields) {
        $graphNode = $this->connect->getFbGraphNode($this->fb, $this->fbPageId, $requestFields);

        if (empty($graphNode) || is_null($graphNode->getField('events'))) {
            $this->log->notice("    - Facebook events not found for " . $this->partyCode);
            return false;
        }

        $this->log->info("    + Getting event details...");
        $fdEvents  = $graphNode->getField('events');
        $timeLimit = $this->db->getTimeLimit('fb', 'E', $this->partyCode, $this->scrapeFull);

        $pageCount = 0;
        $eveCount  = 0;

        do { // process current page of results
            $this->log->debug("       + Page " . $pageCount);
            foreach ($fdEvents as $key => $event) {
                $this->getEventDetails($event);
                $eveCount++;
            }

            $timeCheck = $event->getField('updated_time')->getTimestamp(); // check time of last scraped post
            $pageCount++;

        } while ($timeCheck > $timeLimit && $fdEvents = $this->fb->next($fdEvents));
        // while next page is not null and within our time limit

        $out['eventCount'] = $eveCount;
        $out['events']     = true;
        $this->log->info("      + " . $out['eventCount'] . " events found and processed");

        return $out;
    }


    /**
     * Retrieves the details of an event
     * @param  object $event
     * @return null
     */
    public function getEventDetails($event) {
        $place = $event->getField('place');

        if (!empty($place)) { // must be checked in advance, else will break if null
            $placeName = $place->getField('name');
            $location  = $place->getField('location');
        } else $placeName = null;

        if (!empty($location)) { // must be checked in advance, else will break if null
            $placeAddress = [
                'street'    => $location->getField('street'),
                'city'      => $location->getField('city'),
                'zip'       => $location->getField('zip'),
                'country'   => $location->getField('country'),
                'longitude' => $location->getField('longitude'),
                'latitude'  => $location->getField('latitude')
                ];
        } else $placeAddress = null;

        $commentCount = $this->stats->getStatCount($event->getField('comments'));
        $coverData    = json_decode($event->getField('cover'), true);

        $imgId  = $coverData['id'];
        $imgSrc = $coverData['source'];
        $img    = $imgSrc ? $this->images->saveImage('fb', $this->partyCode, $imgSrc, $imgId) : null;

        $allData = [
            'id'          => $event->getField('id'),
            'start_time'  => $event->getField('start_time')->format('Y-m-d H:i:s'), // string
            'updated'     => $event->getField('updated_time')->format('Y-m-d H:i:s'), // string
            'text'        => $event->getField('name'),
            'description' => $event->getField('description'),
            'image'       => $img,
            'img_source'  => $imgSrc,
            'place'       => $placeName,
            'address'     => $placeAddress,
            'url'         => 'https://www.facebook.com/events/'.$event->getField('id'),
            'attending'   => $event->getField('attending_count'),
            'interested'  => $event->getField('interested_count'),
            'comments'    => $commentCount
        ];

        $this->db->addSocial(
            $this->partyCode,
            SocialMedia::TYPE_FACEBOOK,
            SocialMedia::SUBTYPE_EVENT,
            $event->getField('id'),
            $event->getField('updated_time'), // DateTime
            $event->getField('name'),
            $img,
            $event->getField('interested_count'),
            $allData
        );
    }

}