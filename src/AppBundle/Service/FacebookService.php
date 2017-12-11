<?php
namespace AppBundle\Service;

use Doctrine\ORM\EntityManager;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Validator\Constraints\DateTime;

use AppBundle\Command\ScraperCommand;
use AppBundle\Entity\SocialMedia;

class FacebookService
{
    private   $container;
    protected $connect;
    protected $db;
    protected $images;
    protected $stats;

    public function __construct(Container $container) {
        $this->container = $container;
        $this->connect   = $this->container->get('ConnectionService');
        $this->db        = $this->container->get('DatabaseService');
        $this->images    = $this->container->get('ImageService');
        $this->stats     = $this->container->get('FbStatService');
        @set_exception_handler([$this->db, 'exception_handler']);
    }


    /**
     * Queries for stats, posts, images and events
     * @param  string $fbPageId
     * @param  string $partyCode
     * @param  string $scrapeData
     * @param  bool   $scrapeFull
     * @return array
     */
    public function getFBData($fbPageId, $partyCode, $scrapeFull = false, $scrapeData = null) {
        $fb = $this->connect->getNewFacebook();

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
            $out = $this->stats->getPageInfo($fb, $fbPageId, $requestFields['basic']);
            $out['postCount']  = $this->stats->getPostCount($fb, $fbPageId, $requestFields['postStats']);
            $out['videoCount'] = $this->stats->getVideoCount($fb, $fbPageId, $requestFields['videoStats']);
        }

        if ($scrapeData == 'info') {
            $out['imageCount'] = $this->stats->getImageCount($fb, $fbPageId, $requestFields['imageStats']);
            $out['eventCount'] = $this->stats->getEventCount($fb, $fbPageId, $requestFields['eventStats']);
        }

        if ($scrapeData == null || $scrapeData == 'posts') {
            $temp = $this->getPosts($fb, $fbPageId, $requestFields['postDetails'], $partyCode, $scrapeFull);
            $out['posts']  = isset($temp['posts'])  ? $temp['posts']  : null;
            $out['videos'] = isset($temp['videos']) ? $temp['videos'] : null;
        }

        if ($scrapeData == null || $scrapeData == 'images') {
            $temp = $this->getImages($fb, $fbPageId, $requestFields['imageDetails'], $partyCode, $scrapeFull);
            $out['imageCount'] = isset($temp['imageCount']) ? $temp['imageCount'] : null;
            $out['images']     = isset($temp['images'])     ? $temp['images']     : null;
        }

        if ($scrapeData == null || $scrapeData == 'events') {
            $temp = $this->getEvents($fb, $fbPageId, $requestFields['eventDetails'], $partyCode, $scrapeFull);
            $out['eventCount'] = isset($temp['eventCount']) ? $temp['eventCount'] : null;
            $out['events']     = isset($temp['events'])     ? $temp['events']     : null;
        }

        return $out;
    }


    /**
     * Processes text posts (inc. videos)
     * @param  object $fb
     * @param  string $fbPageId
     * @param  string $requestFields
     * @param  string $partyCode
     * @param  bool   $scrapeFull
     * @return array
     */
    public function getPosts($fb, $fbPageId, $requestFields, $partyCode, $scrapeFull = false) {
        $graphNode = $this->connect->getFbGraphNode($fb, $fbPageId, $requestFields);

        echo "     + Post details.... ";
        if (empty($graphNode)) {
            echo "not found\n";
            return false;
        }

        $fdPosts   = $graphNode->getField('posts');
        $timeLimit = $this->db->getTimeLimit('fb', 'T', $partyCode, $scrapeFull);

        echo "page ";
        $pageCount = 0;
        $txtCount  = 0;
        $vidCount  = 0;

        do {
            echo $pageCount . ', ';

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

                $this->getPostDetails($post, $partyCode, $subType);
            }

            $timeCheck = $post->getField('created_time')->getTimestamp(); // check time of last scraped post
            $pageCount++;

        } while ($timeCheck > $timeLimit && $fdPosts = $fb->next($fdPosts));
        // while next page is not null and within our time limit

        $out['posts']  = $txtCount;
        $out['videos'] = $vidCount;
        echo "..." . $txtCount . " text posts and " . $vidCount . " videos since " . date('d/m/Y', $timeCheck) . " processed\n";

        return (isset($out)) ? $out : null;
    }


    /**
     * Retrieves the details of a text post or video
     * @param  object $post
     * @param  string $partyCode
     * @param  string $subType
     * @return null
     */
    public function getPostDetails($post, $partyCode, $subType) {
        $text   = !empty($post->getField('message')) ? $post->getField('message') : $post->getField('story');
        $imgSrc = $this->images->getFbExtImageSource($post);
        $img    = isset($imgSrc['src']) ? $this->images->saveImage('fb', $partyCode, $imgSrc['src'], $post->getField('id'), $imgSrc['bkp']) :  null;

        $likeCount     = $this->stats->getStatCount($post->getField('likes'));
        $reactionCount = $this->stats->getStatCount($post->getField('reactions'));
        $commentCount  = $this->stats->getStatCount($post->getField('comments'));
        $shareCount    = !empty($post->getField('shares')) ? json_decode($post->getField('shares')->getField('count'), true) : null;

        $postData = [
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
            $partyCode,
            SocialMedia::TYPE_FACEBOOK,
            $subType,
            $post->getField('id'),
            $post->getField('updated_time'), // DateTime
            $text,
            $img,
            $reactionCount,
            $postData
        );
    }

    /**
     * Processes images
     * @param  object $fb
     * @param  string $fbPageId
     * @param  string $requestFields
     * @param  string $partyCode
     * @param  bool   $scrapeFull
     * @return array
     */
    public function getImages($fb, $fbPageId, $requestFields, $partyCode, $scrapeFull = false) {
        $graphNode = $this->connect->getFbGraphNode($fb, $fbPageId, $requestFields);

        echo "     + Image details... ";
        if (empty($graphNode)) {
            echo "not found\n";
            return false;
        }

        $fdAlbums  = $graphNode->getField('albums');
        $timeLimit = $this->db->getTimeLimit('fb', 'I', $partyCode, $scrapeFull);
        echo "page ";
        $pageCount = 0;
        $imgCount  = 0;

        foreach ($fdAlbums as $key => $album) {
            $photoCount[] = $album->getField('photo_count');
            $fdPhotos     = $album->getField('photos');

            if (empty($fdPhotos)) {
                continue;
            }

            do {
                echo $pageCount . ', ';
                foreach ($fdPhotos as $key => $photo) {
                    $this->getImageDetails($fb, $photo, $album, $partyCode);
                    $imgCount++;
                }

                $timeCheck = $photo->getField('updated_time')->getTimestamp(); // check time of last scraped post
                $pageCount++;

            } while ($timeCheck > $timeLimit && $fdPhotos = $fb->next($fdPhotos));
            // while next page is not null and within our time limit
        }

        $out['imageCount'] = array_sum($photoCount);
        $out['images']     = $imgCount;
        echo "..." . $out['imageCount'] . " found, " . $imgCount . " since " . date('d/m/Y', $timeCheck) . " processed\n";

        return $out;
    }


    /**
     * Retrieves the details of an image
     * @param  object $fb
     * @param  object $image
     * @param  object $album
     * @param  string $partyCode
     * @return null
     */
    public function getImageDetails($fb, $photo, $album, $partyCode) {
        $imgSrc = $this->images->getFbImageSource($fb, $photo->getField('id')); // ~480x480 (or closest)
        $imgBkp = $photo->getField('picture'); // 130x130 thumbnail
        $img    = $this->images->saveImage('fb', $partyCode, $imgSrc, $photo->getField('id'), $imgBkp);

        $likeCount     = $this->stats->getStatCount($photo->getField('likes'));
        $reactionCount = $this->stats->getStatCount($photo->getField('reactions'));
        $commentCount  = $this->stats->getStatCount($photo->getField('comments'));
        $shareCount    = count(json_decode($photo->getField('sharedposts'), true));

        $postData = [
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
            $partyCode,
            SocialMedia::TYPE_FACEBOOK,
            SocialMedia::SUBTYPE_IMAGE,
            $photo->getField('id'),
            $photo->getField('updated_time'), // DateTime
            $photo->getField('name'),
            $img,
            $reactionCount,
            $postData
        );
    }


    /**
     * Processes events
     * @param  object $fb
     * @param  string $fbPageId
     * @param  string $requestFields
     * @param  string $partyCode
     * @param  bool   $scrapeFull
     * @return array
     */
    public function getEvents($fb, $fbPageId, $requestFields, $partyCode, $scrapeFull = false) {
        $graphNode = $this->connect->getFbGraphNode($fb, $fbPageId, $requestFields);

        echo "     + Event details... ";
        if (empty($graphNode)) {
            echo "not found\n";
            return false;
        }

        $fdEvents  = $graphNode->getField('events');
        $timeLimit = $this->db->getTimeLimit('fb', 'E', $partyCode, $scrapeFull);
        echo "page ";
        $pageCount = 0;
        $eveCount  = 0;

        do { // process current page of results
            echo $pageCount . ', ';
            foreach ($fdEvents as $key => $event) {
                $this->getEventDetails($event, $partyCode);
                $eveCount++;
            }

            $timeCheck = $event->getField('updated_time')->getTimestamp(); // check time of last scraped post
            $pageCount++;

        } while ($timeCheck > $timeLimit && $fdEvents = $fb->next($fdEvents));
        // while next page is not null and within our time limit

        $out['events'] = $eveCount;
        echo "..." . $out['events'] . " found and processed\n";

        return $out;
    }


    /**
     * Retrieves the details of an event
     * @param  object $event
     * @param  string partyCode
     * @return null
     */
    public function getEventDetails($event, $partyCode) {
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
        $img    = $imgSrc ? $this->images->saveImage('fb', $partyCode, $imgSrc, $imgId) : null;

        $postData = [
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
            $partyCode,
            SocialMedia::TYPE_FACEBOOK,
            SocialMedia::SUBTYPE_EVENT,
            $event->getField('id'),
            $event->getField('updated_time'), // DateTime
            $event->getField('name'),
            $img,
            $event->getField('interested_count'),
            $postData
        );
    }

}