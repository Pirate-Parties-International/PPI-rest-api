<?php
namespace AppBundle\Extensions;

use Doctrine\ORM\EntityManager;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Validator\Constraints\DateTime;

use Pirates\PapiInfo\Compile;

use TwitterAPIExchange;
use Madcoda\Youtube;

use AppBundle\Command\ScraperCommand;
use AppBundle\Entity\Party;
use AppBundle\Entity\Metadata;
use AppBundle\Entity\Statistic;
use AppBundle\Entity\SocialMedia;

use Facebook\Facebook;
use Facebook\FacebookSDKException;
use Facebook\FacebookResponseException;


class ScraperServices
{
    protected $stats = [];
    protected $meta  = [];
    protected $posts = [];
    protected $em;
    private   $container;


    public function exception_handler($ex) {
        echo "Exception: ".$ex->getMessage()."\n";
        $out['errors'][] = ["Exception" => $ex->getMessage()];
        return $out;
    }


    public function __construct(EntityManager $entityManager, Container $container) {
        $this->em = $entityManager;
        $this->container = $container;
        @set_exception_handler(array($this, 'exception_handler'));
    }


    /**
     * Queries DB for all parties
     * @return array
     */
    public function getAllParties() {
        $parties = $this->container->get('doctrine')
            ->getRepository('AppBundle:Party')
            ->findAll();
        
        $allData = array();
        foreach ($parties as $party) {
            $allData[strtolower($party->getCode())] = $party;
        }

        return $allData;
    }


    /**
     * Queries DB for one party
     * @param  string $code
     * @return array
     */
    public function getOneParty($code) {
        $party = $this->container->get('doctrine')
            ->getRepository('AppBundle:Party')
            ->findOneByCode($code);

        if (empty($party)) {
            echo ("   + ERROR - Party code \"". $code ."\" not recognised\n");
            echo ("# Process halted\n");
            die;
        }

        $data = array(); // scraper is set up to work with arrays
        $data[strtolower($party->getCode())] = $party;

        return $data;
    }


    /**
     * Builds a Statistic object
     * @param  string $type
     * @param  string $subType
     * @param  int    $value
     * @return Statistic
     */
    public function addStatistic($code, $type, $subType, $value) {
        $s = new Statistic();
        $s->setCode($code);
        $s->setType($type);
        $s->setSubType($subType);
        $s->setValue($value);
        $s->setTimestamp(new \DateTime());

        $this->em->persist($s);
        
        $this->stats[] = $s;
        return $s;
    }


    /**
     * Builds or updates a Metadata object
     * @param  string $code
     * @param  string $type
     * @param  string $value
     * @return Metadata
     */
    public function addMeta($code, $type, $value) {
        $m = $this->container->get('doctrine')
            ->getRepository('AppBundle:Metadata')
            ->findOneBy([
                'type' => $type,
                'code' => $code
              ]);

        if (!$m) {
            $m = new Metadata();
            $m->setCode($code);
            $m->setType($type);      
        }

        $m->setValue($value);

        $this->em->persist($m);

        $this->meta[] = $m;
        return $m;
    }


    /**
     * Builds or updates a social media object
     * @param  string   $type
     * @param  string   $subType
     * @param  string   $postId
     * @param  dateTime $postTime
     * @param  string   $postText
     * @param  string   $postImage
     * @param  int      $postLikes
     * @param  array    $postData
     * @return SocialMedia
     */
    public function addSocial($code, $type, $subType, $postId, $postTime, $postText, $postImage, $postLikes, $postData) {
        $p = $this->container->get('doctrine')
            ->getRepository('AppBundle:SocialMedia')
            ->findOneByPostId($postId);

        if (!$p) {
            $p = new SocialMedia();
        }

        $p->setCode($code);
        $p->setType($type);
        $p->setSubType($subType);
        $p->setPostId($postId);
        $p->setPostTime($postTime);
        $p->setPostText($postText);
        $p->setPostImage($postImage);
        $p->setPostLikes($postLikes);
        $p->setPostData($postData);
        $p->setTimestamp(new \DateTime());

        $this->em->persist($p);

        $this->posts[] = $p;
        return $p;
    }


    /**
     * Queries DB for a party's latest social media entry of a specified type and subtype
     * @param  string $type
     * @param  string $subType
     * @param  string $code
     * @param  string $what
     * @return int
     */
    public function getTimeLimit($type, $subType, $code, $what) {

        $limited   = strtotime("-1 year"); // set age limit for fb text posts and tweets
        $unlimited = strtotime("-20 years"); // practically no limit, get all

        if ($what == 'info' || $what == 'stats') { // if only getting stats, not full data
            $time = $unlimited;
        } else {

            echo "checking database...";

            $p = $this->container->get('doctrine') // find most recent entry
                ->getRepository('AppBundle:SocialMedia')
                ->createQueryBuilder('qb')
                ->select('p')->from('AppBundle:SocialMedia', 'p')
                ->where(sprintf("p.code = '%s'", $code))
                ->andwhere(sprintf("p.type = '%s'", $type))
                ->andwhere(sprintf("p.subType = '%s'", $subType))
                ->orderBy('p.timestamp', 'DESC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if (empty($p)) { // if there are no entries in the database, populate fully
                if ($subType == 'T' || $type == 'tw') {
                    $time = $limited; // age limit for fb text posts and tweets
                } else {
                    $time = $unlimited; // no limit for fb images/events and yt videos, get all
                }
                echo " empty, getting all... ";

            } else { // if there are entries already in the db, only get updates since the latest one
                echo " !empty, updating... ";
                $time = $p->getPostTime()->getTimestamp();
            }
        }

        return $time;
    }


    /**
    * Counts Facebook likes/reactions/comments etc.
    * @param  string $data
    * @return int
    */
    public function fbCount($data) {
        if (!empty($data)) {
            $meta  = $data->getMetadata();
            $count = (isset($meta['summary']['total_count']) ? $meta['summary']['total_count'] : 0);
        } else {
            $count = 0;
        }

        return $count;
    }


    /**
     * Saves uploaded images to disk
     * @param  string $site
     * @param  string $code
     * @param  string $imgSrc
     * @param  string $imgId
     * @return string
     */
    public function saveImage($site, $code, $imgSrc, $imgId) {

        $appRoot = $this->container->get('kernel')->getRootDir().'/..';
        $imgRoot = $appRoot.'/web/img/'.$site.'-uploads/';

        preg_match('/.+\.(png|jpg)/i', $imgSrc, $matches);
        $imgFmt  = $matches[1];
        $imgName = $imgId.'.'.$imgFmt;
        $imgPath = $imgRoot.$code.'/'.$imgName;

        if (!is_dir($imgRoot.$code.'/')) { // check if directory exists, else create
            mkdir($imgRoot.$code.'/', 0755, true);
        }

        $ctx = stream_context_create(array(
            'http' => array(
                'timeout' => 15
                )
            )
        );

        if (!file_exists($imgPath)) { // check if file exists on disk before saving
            try {
                $imgData = file_get_contents($imgSrc, false, $ctx);
                if (!empty($imgData)) {
                    file_put_contents($imgPath, $imgData);
                }
            } catch (\Exception $e) {
                echo $e->getMessage();
                $out['errors'][] = [$code => $imgPath];
            }
        }

        return $imgName;

    }


    /**
     * Retrives facebook covers and saves them to disk
     * @param  string $code
     * @param  string $imgSrc
     * @return string       local relative path
     */
    public function getFacebookCover($code, $imgSrc) {

        $appRoot   = $this->container->get('kernel')->getRootDir().'/..';
        $imgRoot = $appRoot.'/web/img/fb-covers/';

        if (!is_dir($imgRoot)) {
            mkdir($imgRoot, 0755, true);
        }

        preg_match('/.+\.(png|jpg)/i', $imgSrc, $matches);
        $imgFmt = $matches[1];

        $ctx = stream_context_create(array(
            'http' => array(
                'timeout' => 15
                )
            )
        );

        $imgData = file_get_contents($imgSrc, false, $ctx);
        if (empty($imgData)) {
            return false;
        }

        $imgName = strtolower($code).'.'.$imgFmt;
        $imgPath = $imgRoot.$imgName;
        file_put_contents($imgPath, $imgData);

        $this->cropImage($imgPath);

        return '/img/fb-covers/'.$imgName;
    }


    /**
     * Crops the image
     * @param  string $path
     * @return null
     */
    public function cropImage($path) {
        $crop_width  = 851;
        $crop_height = 315;

        $image    = new \Imagick($path);
        $geometry = $image->getImageGeometry();

        $width  = $geometry['width'];
        $height = $geometry['height'];

        if ($width == $crop_width && $height == $crop_height) {
            return;
        }
        if ($width < $crop_width && $height < $crop_height) {
            return;
        }

        // First we scale
        $x_index = ($width / $crop_width);
        $y_index = ($height / $crop_height);

        if ($x_index > 1 && $y_index > 1) {
            $image->scaleImage($crop_width, 0);
        }

        $geometry = $image->getImageGeometry();
        if ($geometry['height'] > 351) {
            $image->cropImage($crop_width, $crop_height, 0, 0);
        }

        file_put_contents($path, $image);
    }


    /**
     * Queries Facebook for stats, posts, images and events
     * @param  string $fbPageId
     * @param  string $what     type of data being scraped
     * @param  string $code     party code
     * @return array
     */
    public function getFBData($fbPageId, $what, $code) {
        $fb = new Facebook([
            'app_id' => $this->container->getParameter('fb_app_id'),
            'app_secret' => $this->container->getParameter('fb_app_secret'),
            'default_graph_version' => 'v2.7',
        ]);
        $fb->setDefaultAccessToken($this->container->getParameter('fb_access_token'));

        $req = [
            'basic'    => 'cover,engagement,talking_about_count,about,emails,single_line_address',
            'pStats'   => 'posts.limit(100){id}',
            'iStats'   => 'albums{count}',
            'eStats'   => 'events.limit(100){id}',
            'pDetails' => 'posts.limit(50){id,type,permalink_url,message,story,link,name,caption,picture,created_time,updated_time,shares,likes.limit(0).summary(true),reactions.limit(0).summary(true),comments.limit(0).summary(true)}',
            'iDetails' => 'albums{count,photos{created_time,updated_time,picture,source,link,name,likes.limit(0).summary(true),reactions.limit(0).summary(true),comments.limit(0).summary(true),sharedposts.limit(0).summary(true)}}',
            'eDetails' => 'events{start_time,updated_time,name,cover,description,place,attending_count,interested_count,comments.limit(0).summary(true)}'
        ];


        //
        // Basic page info and stats
        //
        if ($what == null || $what == 'info') {

            $request = $fb->request('GET', $fbPageId, ['fields' => $req['basic']]);

            try {
                $response = $fb->getClient()->sendRequest($request);
            } catch(Facebook\Exceptions\FacebookResponseException $e) {
                // When Graph returns an error
                echo 'Graph returned an error: ' . $e->getMessage();
                exit;
            } catch(Facebook\Exceptions\FacebookSDKException $e) {
                // When validation fails or other local issues
                echo 'Facebook SDK returned an error: ' . $e->getMessage();
                exit;
            } catch(\Exception $e) {
                echo $fbPageId . " - Exception: " . $e->getMessage();
                return false;
            }

            $graphNode = $response->getGraphNode();

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

            $coverId = $graphNode->getField('cover')->getField('cover_id'); // set-up for later (line ~900)
            $out['likes']   = $graphNode->getField('engagement')->getField('count');
            $out['talking'] = $graphNode->getField('talking_about_count');
            echo "     + Info and stats... ok\n";
        }


        //
        // Posts
        //
        if ($what == null || $what == 'posts' || $what == 'info') {
            echo "     + Text posts... \n";
        }

        if ($what == null || $what == 'info') { // count only

            $request = $fb->request('GET', $fbPageId, ['fields' => $req['pStats']]);

            try {
                $response = $fb->getClient()->sendRequest($request);
            } catch(Facebook\Exceptions\FacebookResponseException $e) {
                // When Graph returns an error
                echo 'Graph returned an error: ' . $e->getMessage();
                exit;
            } catch(Facebook\Exceptions\FacebookSDKException $e) {
                // When validation fails or other local issues
                echo 'Facebook SDK returned an error: ' . $e->getMessage();
                exit;
            } catch(\Exception $e) {
                echo $fbPageId . " - Exception: " . $e->getMessage();
                return false;
            }

            $graphNode = $response->getGraphNode();
            $fdPcount  = $graphNode->getField('posts');

            if (!empty($fdPcount)) {
                echo "       + Counting... page ";
                $pageCount = 0;

                do {
                    echo $pageCount.', ';

                    foreach ($fdPcount as $key => $post) {
                        $temp['posts'][] = ['id' => $post->getField('id')]; // count all posts
                    }

                    $pageCount++;

                } while ($fdPcount = $fb->next($fdPcount));
                // while next page is not null

                $out['postCount'] = count($temp['posts']);
                echo "...total ". $out['postCount'] ." found";

            } else {
                echo "not found";
                $out['postCount'] = 0;
            }

            echo "\n";
        }

        //
        // Getting details
        //
        if ($what == null || $what == 'posts') {

            $request = $fb->request('GET', $fbPageId, ['fields' => $req['pDetails']]);

            try {
                $response = $fb->getClient()->sendRequest($request);
            } catch(Facebook\Exceptions\FacebookResponseException $e) {
                // When Graph returns an error
                echo 'Graph returned an error: ' . $e->getMessage();
                exit;
            } catch(Facebook\Exceptions\FacebookSDKException $e) {
                // When validation fails or other local issues
                echo 'Facebook SDK returned an error: ' . $e->getMessage();
                exit;
            } catch(\Exception $e) {
                echo $fbPageId . " - Exception: " . $e->getMessage();
                return false;
            }

            $graphNode = $response->getGraphNode();
            $fdPosts   = $graphNode->getField('posts');

            if (!empty($fdPosts)) {

                echo "       + Details... ";
                $timeLimit = $this->getTimeLimit('fb', 'T', $code, $what);
                echo "page ";
                $pageCount = 0;

                do {
                    echo $pageCount .', ';

                    foreach ($fdPosts as $key => $post) {

                        $type = $post->getField('type');
                        if ($type != 'photo' && $type != 'event') { // get images and events seperately
                        // types = 'status', 'link', 'photo', 'video', 'event'

                            $text = ((!empty($post->getField('message'))) ? $post->getField('message') : $post->getField('story'));
                            $likeCount     = $this->fbCount($post->getField('likes'));
                            $reactionCount = $this->fbCount($post->getField('reactions'));
                            $commentCount  = $this->fbCount($post->getField('comments'));
                            $shareCount    = ((!empty($post->getField('shares'))) ? json_decode($post->getField('shares')->getField('count'), true) : 0);

                            $out['posts'][] = [
                                'postId'    => $post->getField('id'),
                                'postTime'  => $post->getField('updated_time'), // DateTime
                                'postText'  => $text,
                                'postImage' => null,
                                'postLikes' => $reactionCount,
                                'postData'  => [
                                    'id'        => $post->getField('id'),
                                    'posted'    => $post->getField('created_time')->format('Y-m-d H:i:s'), // string
                                    'updated'   => $post->getField('updated_time')->format('Y-m-d H:i:s'), // string
                                    'message'   => $post->getField('message'), // main body of text
                                    'story'     => $post->getField('story'), // "[page] shared a link", etc.
                                    'link'      => [
                                        'url'       => $post->getField('link'),
                                        'name'      => $post->getField('name'),
                                        'caption'   => $post->getField('caption'),
                                        'thumb'     => $post->getField('picture')
                                        ],
                                    'url'       => $post->getField('permalink_url'),
                                    'likes'     => $likeCount,
                                    'reactions' => $reactionCount,
                                    'comments'  => $commentCount,
                                    'shares'    => $shareCount
                                    ],
                                ];
                        }
                    }

                    $timeCheck = $post->getField('created_time')->getTimestamp(); // check time of last scraped post
                    $pageCount++;

                } while ($timeCheck > $timeLimit && $fdPosts = $fb->next($fdPosts));
                // while next page is not null and within our time limit

                echo "...".count($out['posts'])." most recent since ".date('d/m/Y', $timeCheck)." processed";

            } else {
                echo "not found";
            }
            echo "\n";
        }


        //
        // Images
        //
        if ($what == 'info') {

            $request = $fb->request('GET', $fbPageId, ['fields' => $req['iStats']]);

            try {
                $response = $fb->getClient()->sendRequest($request);
            } catch(Facebook\Exceptions\FacebookResponseException $e) {
                // When Graph returns an error
                echo 'Graph returned an error: ' . $e->getMessage();
                exit;
            } catch(Facebook\Exceptions\FacebookSDKException $e) {
                // When validation fails or other local issues
                echo 'Facebook SDK returned an error: ' . $e->getMessage();
                exit;
            } catch(\Exception $e) {
                echo $fbPageId . " - Exception: " . $e->getMessage();
                return false;
            }

            $graphNode = $response->getGraphNode();
            $fdAlbums  = $graphNode->getField('albums');
            echo "     + Photos... ";

            if (!empty($fdAlbums)) {
                echo "counting... page ";
                $pageCount = 0;

                foreach ($fdAlbums as $key => $album) {
                    echo $pageCount.", ";
                    $photoCount[] = $album->getField('count');
                    $pageCount++;
                }

                $out['photoCount'] = array_sum($photoCount);
                echo "total ". $out['photoCount'] ." photos found";

            } else {
                echo "not found";
                $out['photoCount'] = 0;
            }

            echo "\n";
        }

        //
        // Getting details
        //
        if ($what == null || $what == 'images') {

            $request = $fb->request('GET', $fbPageId, ['fields' => $req['iDetails']]);

            try {
                $response = $fb->getClient()->sendRequest($request);
            } catch(Facebook\Exceptions\FacebookResponseException $e) {
                // When Graph returns an error
                echo 'Graph returned an error: ' . $e->getMessage();
                exit;
            } catch(Facebook\Exceptions\FacebookSDKException $e) {
                // When validation fails or other local issues
                echo 'Facebook SDK returned an error: ' . $e->getMessage();
                exit;
            } catch(\Exception $e) {
                echo $fbPageId . " - Exception: " . $e->getMessage();
                return false;
            }

            $graphNode = $response->getGraphNode();
            $fdAlbums  = $graphNode->getField('albums');
            echo "     + Photos... ";

            if (!empty($fdAlbums)) {
                $timeLimit = $this->getTimeLimit('fb', 'I', $code, $what);
                echo "page ";
                $pageCount = 0;

                foreach ($fdAlbums as $key => $album) {

                    $photoCount[] = $album->getField('count');
                    $fdPhotos     = $album->getField('photos');

                    if (!empty($fdPhotos)) {
                        do {
                            echo $pageCount .', ';
                            foreach ($fdPhotos as $key => $photo) {

                                $imgId  = $photo->getField('id');
                                $imgSrc = $photo->getField('source');
                                // 'picture'=130x130 thumbnail, 'source'=original file

                                // save image to disk
                                $img = $this->saveImage('fb', $code, $imgSrc, $imgId);

                                $likeCount     = $this->fbCount($photo->getField('likes'));
                                $reactionCount = $this->fbCount($photo->getField('reactions'));
                                $commentCount  = $this->fbCount($photo->getField('comments'));
                                $shareCount    = count(json_decode($photo->getField('sharedposts'), true));

                                $out['photos'][] = [
                                    'postId'    => $imgId,
                                    'postTime'  => $photo->getField('updated_time'), // DateTime
                                    'postText'  => $photo->getField('name'),
                                    'postImage' => $img,
                                    'postLikes' => $reactionCount,
                                    'postData'  => [
                                        'id'        => $imgId,
                                        'posted'    => $photo->getField('created_time')->format('Y-m-d H:i:s'), // string
                                        'updated'   => $photo->getField('updated_time')->format('Y-m-d H:i:s'), // string
                                        'caption'   => $photo->getField('name'),
                                        'source'    => $imgSrc,
                                        'url'       => $photo->getField('link'),
                                        'likes'     => $likeCount,
                                        'reactions' => $reactionCount,
                                        'comments'  => $commentCount,
                                        'shares'    => $shareCount
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
                echo "...".$out['photoCount']." found, ".count($out['photos'])." processed";

            } else {
                $out['photoCount'] = 0;
                echo "not found";
            }
            echo "\n";
        }


        //
        // Events
        //
        if ($what == 'info') { // count only

            $request = $fb->request('GET', $fbPageId, ['fields' => $req['eStats']]);

            try {
                $response = $fb->getClient()->sendRequest($request);
            } catch(Facebook\Exceptions\FacebookResponseException $e) {
                // When Graph returns an error
                echo 'Graph returned an error: ' . $e->getMessage();
                exit;
            } catch(Facebook\Exceptions\FacebookSDKException $e) {
                // When validation fails or other local issues
                echo 'Facebook SDK returned an error: ' . $e->getMessage();
                exit;
            } catch(\Exception $e) {
                echo $fbPageId . " - Exception: " . $e->getMessage();
                return false;
            }

            $graphNode = $response->getGraphNode();
            $fdEvents  = $graphNode->getField('events');
            echo "     + Events... counting... ";

            if (!empty($fdEvents)) {
                echo "page ";
                $pageCount = 0;
                do {
                    echo $pageCount.", ";
                    foreach ($fdEvents as $key => $event) {
                        $temp['events'][] = ['id' => $event->getField('id')];
                    }
                    $pageCount++;
                } while ($fdEvents = $fb->next($fdEvents));
                // while next page is not null

                $out['eventCount'] = count($temp['events']);
                echo "...total ". $out['eventCount'] ." found";

            } else {
                echo "not found";
                $out['postCount'] = 0;
            }

            echo "\n";
        }

        //
        // Getting details
        //
        if ($what == null || $what == 'events') {

            $request = $fb->request('GET', $fbPageId, ['fields' => $req['eDetails']]);

            try {
                $response = $fb->getClient()->sendRequest($request);
            } catch(Facebook\Exceptions\FacebookResponseException $e) {
                // When Graph returns an error
                echo 'Graph returned an error: ' . $e->getMessage();
                exit;
            } catch(Facebook\Exceptions\FacebookSDKException $e) {
                // When validation fails or other local issues
                echo 'Facebook SDK returned an error: ' . $e->getMessage();
                exit;
            } catch(\Exception $e) {
                echo $fbPageId . " - Exception: " . $e->getMessage();
                return false;
            }

            $graphNode = $response->getGraphNode();
            $fdEvents  = $graphNode->getField('events');
            echo "     + Events... ";

            if (!empty($fdEvents)) {

                $timeLimit = $this->getTimeLimit('fb', 'E', $code, $what);
                echo "page ";
                $pageCount = 0;

                do { // process current page of results
                    echo $pageCount .', ';
                    if (!empty($fdEvents)) {
                        foreach ($fdEvents as $key => $event) {

                            $place = $event->getField('place');
                            if (!empty($place)) { // must be checked in advance, will break if null
                            $placeName = $place->getField('name');
                            $location  = $place->getField('location');
                            } else $placeName = null;

                            if (!empty($location)) { // must be checked in advance, will break if null
                            $placeAddress = [
                                'street'    => $location->getField('street'),
                                'city'      => $location->getField('city'),
                                'zip'       => $location->getField('zip'),
                                'country'   => $location->getField('country'),
                                'longitude' => $location->getField('longitude'),
                                'latitude'  => $location->getField('latitude')
                                ];
                            } else $placeAddress = null;

                            $commentCount = $this->fbCount($event->getField('comments'));
                            $coverData    = json_decode($event->getField('cover'), true);

                            $imgId  = $coverData['id'];
                            $imgSrc = $coverData['source'];

                            // save cover image to disk
                            if (!empty($imgSrc)) {
                                $img = $this->saveImage('fb', $code, $imgSrc, $imgId);
                            } else {
                                $img = null;
                            }

                            $out['events'][] = [
                                'postId'    => $event->getField('id'),
                                'postTime'  => $event->getField('updated_time'), // DateTime
                                'postText'  => $event->getField('name'),
                                'postImage' => $img,
                                'postLikes' => $event->getField('interested_count'),
                                'postData'  => [
                                    'id'         => $event->getField('id'),
                                    'start_time' => $event->getField('start_time')->format('Y-m-d H:i:s'), // string
                                    'updated'    => $event->getField('updated_time')->format('Y-m-d H:i:s'), // string
                                    'name'       => $event->getField('name'),
                                    'details'    => [
                                        'description' => $event->getField('description'),
                                        'place'       => $placeName,
                                        'address'     => $placeAddress,
                                        'cover'       => [
                                            'id'          => $imgId,
                                            'source'      => $imgSrc
                                            ]
                                        ],
                                    'url'        => 'https://www.facebook.com/events/'.$event->getField('id'),
                                    'attending'  => $event->getField('attending_count'),
                                    'interested' => $event->getField('interested_count'),
                                    'comments'   => $commentCount
                                    ]
                                ];
                        }

                        $timeCheck = $event->getField('updated_time')->getTimestamp(); // check time of last scraped post
                        $pageCount++;
                    }

                } while ($timeCheck > $timeLimit && $fdEvents = $fb->next($fdEvents));
                // while next page is not null and within our time limit

                $out['eventCount'] = count($out['events']);
                echo "...".$out['eventCount']." found and processed";

            } else {
                $out['eventCount'] = 0;
                echo "not found";
            }

            echo "\n";
        }


        //
        // Second step for cover images (crop and/or resize)
        //
        if ($what == null || $what == 'info') {

            $request = $fb->request(
                'GET',
                $coverId, // retrieved earlier (line ~435)
                array(
                    'fields' => 'height,width,album,images'
                )
            );

            try {
                $response = $fb->getClient()->sendRequest($request);
            } catch(Facebook\Exceptions\FacebookResponseException $e) {
                // When Graph returns an error
                echo 'Graph returned an error: ' . $e->getMessage();
                exit;
            } catch(Facebook\Exceptions\FacebookSDKException $e) {
                // When validation fails or other local issues
                echo 'Facebook SDK returned an error: ' . $e->getMessage();
                exit;
            } catch (\Exception $e) {
                echo $fbPageId . " - Exception: " . $e->getMessage();
                return false;
            }

            $graphNode = $response->getGraphNode();
            $images    = $graphNode->getField('images');

            $tmpI = [];
            $tmpA = [];
            foreach ($images as $key => $img) {
                if ($img->getField('width') == 851 && $img->getField('height') == 351) {
                    $out['cover'] = $img->getField('source');
                    return $out;
                } else if ($img->getField('width') > 851 && $img->getField('height') > 351) {
                    $tmpI[$img->getField('width') + $img->getField('height')] = $img->getField('source');
                } else {
                    $tmpA[$img->getField('width') + $img->getField('height')] = $img->getField('source');
                }
            }

            if (!empty($tmpI)) {
                $t   = max(array_keys($tmpI));
                $img = $tmpI[$t];
            } else {
                $t   = max(array_keys($tmpA));
                $img = $tmpA[$t];
            }

            $out['cover'] = $img;
        }

        return $out;
    }


    /**
     * Queries Twitter for stats and tweets
     * @param  string $username
     * @param  string $code     party code
     * @return array
     */
    public function getTwitterData($username, $code) {
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
        echo "     + Info and stats... ok\n";


        //
        // Tweet details
        //
        $tweetUrl  = 'https://api.twitter.com/1.1/statuses/user_timeline.json';
        $tweetData = $twitter->setGetField($getfield)
                ->buildOauth($tweetUrl, $requestMethod)
                ->performRequest();

        echo "     + Tweets... ";
        if (empty($tweetData)) {
            echo "not found\n";
            return false;
        } else {
            $tweetData = json_decode($tweetData);
            $timeLimit = $this->getTimeLimit('tw', 'T', $code, 'tweets');
            $pageCount = 0;
            echo "page ";

            do { // process current page of results
                echo $pageCount .', ';

                foreach($tweetData as $item) {

                    $image  = null;
                    $twTime = \DateTime::createFromFormat('D M d H:i:s P Y', $item->created_at);
                    // original string e.g. 'Mon Sep 08 15:19:11 +0000 2014'

                    if (!empty($item->entities->media)) { // if tweet contains an image
                        $media = $item->entities->media;
                        foreach ($media as $photo) {
                            if ($photo->type == 'photo') {
                                $imgSrc = $photo->media_url;
                                $imgId  = $photo->id;

                                // save image to disk
                                $img = $this->saveImage('tw', $code, $imgSrc, $imgId);

                                $out['images'][] = [
                                    'postId'    => $imgId,
                                    'postTime'  => $twTime, // DateTime
                                    'postText'  => $item->text,
                                    'postImage' => $img,
                                    'postLikes' => $item->favorite_count,
                                    'postData'  => [
                                        'id'       => $imgId,
                                        'posted'   => $twTime->format('Y-m-d H:i:s'), // string
                                        'text'     => $item->text,
                                        'image'    => $imgSrc,
                                        'url'      => 'https://twitter.com/statuses/'.$item->id,
                                        'likes'    => $item->favorite_count,
                                        'retweets' => $item->retweet_count
                                        ]
                                    ];
                            }
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
                }

                $timeCheck = $twTime->getTimestamp(); // check time of last tweet scraped

                // check rate limit
                $limitUrl   = 'https://api.twitter.com/1.1/application/rate_limit_status.json';
                $limitData  = json_decode($twitter->buildOauth($limitUrl, $requestMethod)->performRequest(), true);
                $limitCheck = $limitData['resources']['application']['/application/rate_limit_status'];

                if ($limitCheck['remaining'] < 2) { // give ourselves a little bit of wiggle room
                    echo "...Rate limit reached! Resuming at ".date('H:i:s', $limitCheck['reset'])."... ";
                    time_sleep_until($limitCheck['reset']);
                }

                // make new request to get next page of results
                $nextField = '?screen_name='.str_replace("@", "", $username).'&max_id='.($item->id).'&count=50';
                $tweetData = json_decode($twitter->setGetField($nextField)
                    ->buildOauth($tweetUrl, $requestMethod)
                    ->performRequest());

                $pageCount++;

            } while ($timeCheck > $timeLimit && $pageCount < 100);
            // while tweet times are more recent than the limit as set above, up to 5000

            echo "...total ".$out['tweets']." tweets found, ".count($out['posts'])." most recent since ".date('d/m/Y', $timeCheck)." processed\n";
        }

        return $out;
    }


    /**
     * Queries Youtube for stats and videos
     * @param  string $id
     * @param  string $code     party code
     * @return array
     */
    public function getYoutubeData($id, $code) {
        $apikey  = $this->container->getParameter('gplus_api_key');
        $youtube = new Youtube(array('key' => $apikey));

        $data = $youtube->getChannelByName($id);

        if (empty($data)) {
            return false;
        }
        if (empty($data->statistics) || empty($data->statistics->viewCount)) {
            return false;
        }
        $out['stats']['viewCount']       = $data->statistics->viewCount;
        $out['stats']['subscriberCount'] = $data->statistics->subscriberCount;
        $out['stats']['videoCount']      = $data->statistics->videoCount;

        $playlist = $data->contentDetails->relatedPlaylists->uploads;
        $videos   = $youtube->getPlaylistItemsByPlaylistId($playlist);

        echo "     + Videos... ";

        if (!empty($videos)) {
            $out['videos'] = [];

            foreach ($videos as $key => $vid) {

                $vidId   = $vid->snippet->resourceId->videoId;
                $vidInfo = $youtube->getVideoInfo($vidId);
                $imgSrc  = $vid->snippet->thumbnails->medium->url;
                // deafult=120x90, medium=320x180, high=480x360, standard=640x480, maxres=1280x720

                // save thumbnail to disk
                $img = $this->saveImage('yt', $code, $imgSrc, $vidId);

                $vidTime = \DateTime::createFromFormat('Y-m-d\TH:i:s.u\Z', $vid->snippet->publishedAt);
                // original ISO 8601, e.g. '2015-04-30T21:45:59.000Z'

                if (!empty($vidInfo->statistics->likeCount)) {
                    $vidLikes = $vidInfo->statistics->likeCount;
                } else {
                    $vidLikes = "0";
                }

                if (!empty($vidInfo->statistics->commentCount)) {
                    $vidComments = $vidInfo->statistics->commentCount;
                } else {
                    $vidComments = "0";
                }

                $out['videos'][] = [
                    'postId'    => $vidId,
                    'postTime'  => $vidTime, // DateTime
                    'postText'  => $vid->snippet->title,
                    'postImage' => $img,
                    'postLikes' => $vidLikes,
                    'postData'  => [
                        'id'       => $vidId,
                        'posted'   => $vidTime->format('Y-m-d H:i:s'), // string
                        'title'    => $vid->snippet->title,
                        'thumb'    => $imgSrc,
                        'url'      => 'https://www.youtube.com/watch?v='.$vidId,
                        'views'    => $vidInfo->statistics->viewCount,
                        'likes'    => $vidLikes,
                        'comments' => $vidComments
                    ]
                ];
            }
            echo "processed\n";

        } else {
            echo "not found\n";
        }

        return $out;

    }


    /**
     * Queries Google+ for followers
     * @param  string $id
     * @return int
     */
    public function getGooglePlusData($id) {
        $apikey = $this->container->getParameter('gplus_api_key');
        $google = $this->curl(
            sprintf('https://www.googleapis.com/plus/v1/people/%s?key=%s',
                $id, $apikey)
            );
        $data = json_decode( $google );
        if (empty($data) || !isset($data->circledByCount)) {
            return false;
        }
        return $data->circledByCount;

    }


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

        $tryCount = 0;
        do {
            try {
                // Send the request & save response to $resp
                $resp = curl_exec($curl);
                $tryCount++;
            } catch (\Exception $e) {
                echo $e->getMessage();
                $out['errors'][] = [$code => $e->getMessage()];
                return $out;
            }
        } while ($tryCount < 5);

        // Close request to clear up some resources
        curl_close($curl);

        return $resp;
    }


}