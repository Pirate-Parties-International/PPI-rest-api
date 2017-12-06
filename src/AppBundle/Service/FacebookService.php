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

class FacebookService extends ScraperServices
{
    protected $em;
    protected $parent;
    protected $images;
    protected $connect;
    private   $container;

    public function __construct(EntityManager $entityManager, Container $container) {
        $this->em        = $entityManager;
        $this->container = $container;

        $this->parent    = $this->container->get('ScraperServices');
        $this->images    = $this->container->get('ImageService');
        $this->connect   = $this->container->get('ConnectionService');

        @set_exception_handler([$this->parent, 'exception_handler']);
    }


    /**
    * Counts likes/reactions/comments etc.
    * @param  string $data
    * @return int
    */
    public function getStatCount($data) {
        if (!empty($data)) {
            $meta  = $data->getMetadata();
            $count = isset($meta['summary']['total_count']) ? $meta['summary']['total_count'] : null;
        } else {
            $count = null;
        }

        return $count;
    }

//
// Getting info
//

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
            $out = $this->getPageInfo($fb, $fbPageId, $requestFields['basic']);
            $out['postCount']  = $this->getPostCount($fb, $fbPageId, $requestFields['postStats']);
            $out['videoCount'] = $this->getVideoCount($fb, $fbPageId, $requestFields['videoStats']);
        }

        if ($scrapeData == 'info') {
            $out['photoCount'] = $this->getImageCount($fb, $fbPageId, $requestFields['imageStats']);
            $out['eventCount'] = $this->getEventCount($fb, $fbPageId, $requestFields['eventStats']);
        }

        if ($scrapeData == null || $scrapeData == 'posts') {
            $temp = $this->getPostDetails($fb, $fbPageId, $requestFields['postDetails'], $partyCode, $scrapeFull);
            $out['posts']  = isset($temp['posts'])  ? $temp['posts']  : null;
            $out['videos'] = isset($temp['videos']) ? $temp['videos'] : null;
        }

        if ($scrapeData == null || $scrapeData == 'images') {
            $temp = $this->getImageDetails($fb, $fbPageId, $requestFields['imageDetails'], $partyCode, $scrapeFull);
            $out['photoCount'] = isset($temp['photoCount']) ? $temp['photoCount'] : null;
            $out['photos']     = isset($temp['photos'])     ? $temp['photos']     : null;
        }

        if ($scrapeData == null || $scrapeData == 'events') {
            $temp = $this->getEventDetails($fb, $fbPageId, $requestFields['eventDetails'], $partyCode, $scrapeFull);
            $out['eventCount'] = isset($temp['eventCount']) ? $temp['eventCount'] : null;
            $out['events']     = isset($temp['events'])     ? $temp['events']     : null;
        }

        return $out;
    }


//
// Counting stats
//

    /**
     * Basic info about a FB page
     * @param  object $fb
     * @param  string $fbPageId
     * @param  stting $requestFields
     * @return array
     */
    public function getPageInfo($fb, $fbPageId, $requestFields) {
        $graphNode = $this->connect->getFbGraphNode($fb, $fbPageId, $requestFields);

        echo "     + Info and stats.... ";
        if (!$graphNode) {
            echo "not found\n";
            return false;
        }

        $out['info'] = [
            'about'   => $graphNode->getField('about'),
            'address' => $graphNode->getField('single_line_address')
        ];

        $fdEmails = $graphNode->getField('emails');
        if (!empty($fdEmails)) {
            foreach ($fdEmails as $key => $email) {
                $out['info']['email'][] = $email;
            }
        }

        $coverId = !empty($graphNode->getField('cover')) ? $graphNode->getField('cover')->getField('cover_id') : null;
        $out['cover'] = !is_null($coverId) ? $this->images->getFbImageSource($fb, $coverId, true) : null;

        $out['likes']   = !empty($graphNode->getField('engagement')) ? $graphNode->getField('engagement')->getField('count') : null;
        $out['talking'] = !empty($graphNode->getField('talking_about_count')) ? $graphNode->getField('talking_about_count') : null;

        echo "ok\n";

        return $out;
    }


    /**
     * Post count for stats only
     * @param  object $fb
     * @param  string $fbPageId
     * @param  string $requestFields
     * @return int
     */
    public function getPostCount($fb, $fbPageId, $requestFields) {
        $graphNode = $this->connect->getFbGraphNode($fb, $fbPageId, $requestFields);

        echo "     + Counting posts.... ";
        if (!$graphNode) {
            echo "not found\n";
            return false;
        }

        $fdPcount  = $graphNode->getField('posts');
        echo "page ";
        $pageCount = 0;

        do {
            echo $pageCount . ', ';
            foreach ($fdPcount as $key => $post) {
                $temp['posts'][] = ['id' => $post->getField('id')]; // count all posts
            }
            $pageCount++;
        } while ($fdPcount = $fb->next($fdPcount)); // while next page is not null

        $out['postCount'] = count($temp['posts']);
        echo "...total " . $out['postCount'] . " found\n";

        return $out['postCount'];
    }


    /**
     * Image count for stats only
     * @param  string $fb
     * @param  string $fbPageId
     * @param  string $requestFields
     * @return int
     */
    public function getImageCount($fb, $fbPageId, $requestFields) {
        $graphNode = $this->connect->getFbGraphNode($fb, $fbPageId, $requestFields);

        echo "     + Counting photos... ";
        if (!$graphNode) {
            echo "not found\n";
            return false;
        }

        $fdAlbums  = $graphNode->getField('albums');
        echo "page ";
        $pageCount = 0;

        foreach ($fdAlbums as $key => $album) {
            echo $pageCount . ", ";
            $photoCount[] = $album->getField('photo_count');
            $pageCount++;
        }

        $out['photoCount'] = array_sum($photoCount);
        echo "...total " . $out['photoCount'] . " found\n";

        return $out['photoCount'];
    }


    /**
     * Video count for stats only
     * @param  string $fb
     * @param  string $fbPageId
     * @param  string $requestFields
     * @return int
     */
    public function getVideoCount($fb, $fbPageId, $requestFields) {
        $graphNode = $this->connect->getFbGraphNode($fb, $fbPageId, $requestFields);

        echo "     + Counting videos... ";
        if (!$graphNode) {
            echo "not found\n";
            return false;
        }

        $fdVcount  = $graphNode->getField('videos');
        echo "page ";
        $pageCount = 0;

        do {
            echo $pageCount . ', ';
            foreach ($fdVcount as $key => $post) {
                $temp['videos'][] = ['id' => $post->getField('id')]; // count all posts
            }
            $pageCount++;
        } while ($fdVcount = $fb->next($fdVcount)); // while next page is not null

        $out['videoCount'] = count($temp['videos']);
        echo "...total " . $out['videoCount'] . " found\n";

        return $out['videoCount'];
    }


    /**
     * Event count for stats only
     * @param  string $fb
     * @param  string $fbPageId
     * @param  string $requestFields
     * @return int
     */
    public function getEventCount($fb, $fbPageId, $requestFields) {
        $graphNode = $this->connect->getFbGraphNode($fb, $fbPageId, $requestFields);

        echo "     + Counting events... ";
        if (!$graphNode) {
            echo "not found.\n";
            return false;
        }

        $fdEvents  = $graphNode->getField('events');
        echo "page ";
        $pageCount = 0;

        do {
            echo $pageCount . ", ";
            foreach ($fdEvents as $key => $event) {
                $temp['events'][] = ['id' => $event->getField('id')];
            }
            $pageCount++;
        } while ($fdEvents = $fb->next($fdEvents)); // while next page is not null

        $out['eventCount'] = count($temp['events']);
        echo "...total " . $out['eventCount'] . " found\n";

        return $out['eventCount'];
    }


//
// Getting details
//

    /**
     * Gets post details (inc. videos)
     * @param  string $fb
     * @param  string $fbPageId
     * @param  string $requestFields
     * @param  string $partyCode
     * @param  bool   $scrapeFull
     * @return array
     */
    public function getPostDetails($fb, $fbPageId, $requestFields, $partyCode, $scrapeFull = false) {
        $graphNode = $this->connect->getFbGraphNode($fb, $fbPageId, $requestFields);

        echo "     + Post details.... ";
        if (!$graphNode) {
            echo "not found\n";
            return false;
        }

        $fdPosts   = $graphNode->getField('posts');
        $timeLimit = $this->parent->getTimeLimit('fb', 'T', $partyCode, $scrapeFull);
        echo "page ";
        $pageCount = 0;

        do {
            echo $pageCount . ', ';

            foreach ($fdPosts as $key => $post) {

                $type = $post->getField('type'); // types = 'status', 'link', 'photo', 'video', 'event'
                if ($type != 'photo' && $type != 'event') { // get photos and events separately to get all details

                    // $img = null;
                    if ($type == 'video') {
                        $temp = $post->getField('link');
                        if (strpos($temp, 'youtu')) { // youtube.com or youtu.be

                            $temp = urldecode($temp);
                            switch (true) {
                                case strpos($temp, 'v='):
                                    $idPos = strpos($temp, 'v=')+2;
                                    break;
                                case strpos($temp, 'youtu.be/'):
                                    $idPos = strpos($temp, '.be/')+4;
                                    break;
                                }

                            $vidId  = substr($temp, $idPos, 11);
                            $imgSrc = "https://img.youtube.com/vi/".$vidId."/mqdefault.jpg"; // 320x180 (only 16:9 option)
                            // default=120x90, mqdefault=320x180, hqdefault=480x360, sddefault=640x480 (all 4:3 w/ letterbox)
                            $imgBkp = $post->getField('picture'); // 130x130 thumbnail
                        } else {
                            $imgSrc = $post->getField('picture');
                            $imgBkp = null;
                        }
                    } else {
                        $type   = 'post';
                        $imgSrc = $post->getField('picture') ? $post->getField('picture') : null;
                        $imgBkp = null;
                    }

                    if ($imgSrc && strpos($imgSrc, 'external.xx.fbcdn.net')) {
                        $stPos  = strpos($imgSrc, '&url=')+5;
                        $edPos  = strpos($imgSrc, '&cfs=');
                        $length = $edPos - $stPos;
                        $temp   = substr($imgSrc, $stPos, $length);
                        $imgSrc = urldecode($temp);
                    }

                    $img  = $imgSrc ? $this->images->saveImage('fb', $partyCode, $imgSrc, $post->getField('id'), $imgBkp) :  null;
                    $text = !empty($post->getField('message')) ? $post->getField('message') : $post->getField('story');

                    $likeCount     = $this->getStatCount($post->getField('likes'));
                    $reactionCount = $this->getStatCount($post->getField('reactions'));
                    $commentCount  = $this->getStatCount($post->getField('comments'));
                    $shareCount    = !empty($post->getField('shares')) ? json_decode($post->getField('shares')->getField('count'), true) : null;

                    $out[$type.'s'][] = [
                        'postId'    => $post->getField('id'),
                        'postTime'  => $post->getField('updated_time'), // DateTime
                        'postText'  => $text,
                        'postImage' => $img,
                        'postLikes' => $reactionCount,
                        'postData'  => [
                            'id'         => $post->getField('id'),
                            'posted'     => $post->getField('created_time')->format('Y-m-d H:i:s'), // string
                            'updated'    => $post->getField('updated_time')->format('Y-m-d H:i:s'), // string
                            'text'       => $text,
                            'image'      => $img,
                            'img_source' => $imgSrc,
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
                            ],
                        ];
                }
            }

            $timeCheck = $post->getField('created_time')->getTimestamp(); // check time of last scraped post
            $pageCount++;

        } while ($timeCheck > $timeLimit && $fdPosts = $fb->next($fdPosts));
        // while next page is not null and within our time limit

        $txtCount = array_key_exists('posts',  $out) ? count($out['posts'])  : 0;
        $vidCount = array_key_exists('videos', $out) ? count($out['videos']) : 0;
        echo "..." . $txtCount . " text posts and " . $vidCount . " videos since " . date('d/m/Y', $timeCheck) . " processed\n";

        return (isset($out)) ? $out : null;
    }


    /**
     * Gets image details
     * @param  string $fb
     * @param  string $fbPageId
     * @param  string $requestFields
     * @param  string $partyCode
     * @param  bool   $scrapeFull
     * @return array
     */
    public function getImageDetails($fb, $fbPageId, $requestFields, $partyCode, $scrapeFull = false) {
        $graphNode = $this->connect->getFbGraphNode($fb, $fbPageId, $requestFields);

        echo "     + Photo details... ";
        if (!$graphNode) {
            echo "not found\n";
            return false;
        }

        $fdAlbums  = $graphNode->getField('albums');
        $timeLimit = $this->parent->getTimeLimit('fb', 'I', $partyCode, $scrapeFull);
        echo "page ";
        $pageCount = 0;

        foreach ($fdAlbums as $key => $album) {
            $photoCount[] = $album->getField('photo_count');
            $fdPhotos     = $album->getField('photos');
            if (!empty($fdPhotos)) {
                do {
                    echo $pageCount . ', ';
                    foreach ($fdPhotos as $key => $photo) {

                        $imgSrc = $this->images->getFbImageSource($fb, $photo->getField('id')); // ~480x480 (or closest)
                        $imgBkp = $photo->getField('picture'); // 130x130 thumbnail
                        $img    = $this->images->saveImage('fb', $partyCode, $imgSrc, $photo->getField('id'), $imgBkp);

                        $likeCount     = $this->getStatCount($photo->getField('likes'));
                        $reactionCount = $this->getStatCount($photo->getField('reactions'));
                        $commentCount  = $this->getStatCount($photo->getField('comments'));
                        $shareCount    = count(json_decode($photo->getField('sharedposts'), true));
                        $out['photos'][] = [
                            'postId'    => $photo->getField('id'),
                            'postTime'  => $photo->getField('updated_time'), // DateTime
                            'postText'  => $photo->getField('name'),
                            'postImage' => $img,
                            'postLikes' => $reactionCount,
                            'postData'  => [
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
                                ]
                            ];
                    }
                    $timeCheck = $photo->getField('updated_time')->getTimestamp(); // check time of last scraped post
                    $pageCount++;
                } while ($timeCheck > $timeLimit && $fdPhotos = $fb->next($fdPhotos));
                // while next page is not null and within our time limit
            }
        }

        $out['photoCount'] = array_sum($photoCount);
        echo "..." . $out['photoCount'] . " found, " . count($out['photos']) . " since " . date('d/m/Y', $timeCheck) . " processed\n";

        return $out;
    }


    /**
     * Gets event details
     * @param  string $fb
     * @param  string $fbPageId
     * @param  string $requestFields
     * @param  string $partyCode
     * @param  bool   $scrapeFull
     * @return array
     */
    public function getEventDetails($fb, $fbPageId, $requestFields, $partyCode, $scrapeFull = false) {
        $graphNode = $this->connect->getFbGraphNode($fb, $fbPageId, $requestFields);

        echo "     + Event details... ";
        if (!$graphNode) {
            echo "not found\n";
            return false;
        }

        $fdEvents  = $graphNode->getField('events');
        $timeLimit = $this->parent->getTimeLimit('fb', 'E', $partyCode, $scrapeFull);
        echo "page ";
        $pageCount = 0;

        do { // process current page of results
            echo $pageCount . ', ';
            foreach ($fdEvents as $key => $event) {

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

                $commentCount = $this->getStatCount($event->getField('comments'));
                $coverData    = json_decode($event->getField('cover'), true);

                $imgId  = $coverData['id'];
                $imgSrc = $coverData['source'];
                $img    = $imgSrc ? $this->images->saveImage('fb', $partyCode, $imgSrc, $imgId) : null;

                $out['events'][] = [
                    'postId'    => $event->getField('id'),
                    'postTime'  => $event->getField('updated_time'), // DateTime
                    'postText'  => $event->getField('name'),
                    'postImage' => $img,
                    'postLikes' => $event->getField('interested_count'),
                    'postData'  => [
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
                        ]
                    ];
            }

            $timeCheck = $event->getField('updated_time')->getTimestamp(); // check time of last scraped post
            $pageCount++;

        } while ($timeCheck > $timeLimit && $fdEvents = $fb->next($fdEvents));
        // while next page is not null and within our time limit

        $out['eventCount'] = count($out['events']);
        echo "..." . $out['eventCount'] . " found and processed\n";

        return $out;
    }

}