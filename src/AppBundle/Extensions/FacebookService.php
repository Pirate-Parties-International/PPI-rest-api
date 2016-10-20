<?php
namespace AppBundle\Extensions;

use Doctrine\ORM\EntityManager;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Validator\Constraints\DateTime;

use AppBundle\Command\ScraperCommand;
use AppBundle\Extensions\ScraperServices;

use Facebook\Facebook;
use Facebook\FacebookSDKException;
use Facebook\FacebookResponseException;

class FacebookService extends ScraperServices
{
    protected $em;
    private   $container;

    public function __construct(EntityManager $entityManager, Container $container) {
        $this->em = $entityManager;
        $this->container = $container;
        @set_exception_handler(array($scraper, 'exception_handler'));
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
     * Retrives facebook covers and saves them to disk
     * @param  string $code
     * @param  string $imgSrc
     * @return string       local relative path
     */
    public function getFacebookCover($code, $imgSrc) {

        $appRoot = $this->container->get('kernel')->getRootDir().'/..';
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
    	$scraper = $this->container->get('ScraperServices');

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
                $timeLimit = $scraper->getTimeLimit('fb', 'T', $code, $what);
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

                echo "...".count($out['posts'])." since ".date('d/m/Y', $timeCheck)." processed";

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
                $timeLimit = $scraper->getTimeLimit('fb', 'I', $code, $what);
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
                                $imgBkp = $photo->getField('picture');
                                // 'picture'=130x130 thumbnail, 'source'=original file

                                // save image to disk
                                $img = $scraper->saveImage('fb', $code, $imgSrc, $imgId);

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
                echo "...".$out['photoCount']." found, ".count($out['photos'])." since ".date('d/m/Y', $timeCheck)." processed";

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

                $timeLimit = $scraper->getTimeLimit('fb', 'E', $code, $what);
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
                                $img = $scraper->saveImage('fb', $code, $imgSrc, $imgId);
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


}